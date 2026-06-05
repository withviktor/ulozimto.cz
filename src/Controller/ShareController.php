<?php

namespace App\Controller;

use App\Repository\FileRepository;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class ShareController extends AbstractController
{
    public function __construct(
        private readonly FileRepository         $files,
        private readonly MinioService           $minio,
        private readonly FileExpirationService  $expiration,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Info stránka sdíleného souboru — zobrazí název, velikost, expiraci a tlačítko ke stažení */
    #[Route('/s/{token}', name: 'share_show', methods: ['GET', 'POST'])]
    public function show(string $token, Request $request): Response
    {
        $file = $this->files->findByShareToken($token);

        if (!$file || $file->isExpired()) {
            throw $this->createNotFoundException('Soubor neexistuje nebo vypršela jeho platnost.');
        }

        // Soubor chráněný heslem — ověř heslo před zobrazením stránky
        if ($file->isPasswordProtected()) {
            $submittedPassword = $request->request->get('password');

            if (!$submittedPassword || !password_verify($submittedPassword, $file->getPasswordHash())) {
                return $this->render('share/password.html.twig', [
                    'file'  => $file,
                    'error' => $submittedPassword !== null ? 'Nesprávné heslo.' : null,
                ]);
            }

            // Heslo správné → uložit do session, aby uživatel nemusel zadávat znovu při stahování
            $request->getSession()->set('unlocked_' . $token, true);
        }

        // Preview URL pro obrázky a videa — jde přes Symfony proxy (žádné CORS)
        $previewUrl = null;
        $mime = $file->getMimeType() ?? '';
        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            $previewUrl = $this->generateUrl('share_preview', ['token' => $file->getShareToken()]);
        }

        return $this->render('share/show.html.twig', [
            'file'       => $file,
            'expiration' => $this->expiration,
            'previewUrl' => $previewUrl,
        ]);
    }

    /**
     * Proxy preview — streamuje obrázek/video přes Symfony.
     * Žádné CORS problémy protože request jde na stejný origin jako stránka.
     */
    #[Route('/s/{token}/preview', name: 'share_preview', methods: ['GET'])]
    public function preview(string $token, Request $request): Response
    {
        $file = $this->files->findByShareToken($token);

        if (!$file || $file->isExpired()) {
            throw $this->createNotFoundException();
        }

        if ($file->isPasswordProtected()) {
            if (!$request->getSession()->get('unlocked_' . $token, false)) {
                return new Response('Unauthorized', 401);
            }
        }

        $mime   = $file->getMimeType() ?? 'application/octet-stream';
        $stream = $this->minio->getObjectStream($file->getMinioKey());

        $response = new StreamedResponse(function () use ($stream) {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'private, max-age=300');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** Samotné stažení — přesměruje na presigned MinIO URL */
    #[Route('/s/{token}/download', name: 'share_download', methods: ['GET'])]
    public function download(string $token, Request $request): Response
    {
        $file = $this->files->findByShareToken($token);

        if (!$file || $file->isExpired()) {
            throw $this->createNotFoundException('Soubor neexistuje nebo vypršela jeho platnost.');
        }

        // Zkontrolovat heslo ze session (bylo ověřeno na info stránce)
        if ($file->isPasswordProtected()) {
            $unlocked = $request->getSession()->get('unlocked_' . $token, false);
            if (!$unlocked) {
                return $this->redirectToRoute('share_show', ['token' => $token]);
            }
        }

        $file->incrementDownloadCount();
        $this->em->flush();

        $url = $this->minio->getPresignedUrl($file->getMinioKey(), 3600);

        return $this->redirect($url);
    }
}
