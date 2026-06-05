<?php

namespace App\Controller;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    /** Info stránka sdíleného souboru — podporuje token i vlastní alias */
    #[Route('/s/{token}', name: 'share_show', methods: ['GET', 'POST'])]
    public function show(string $token, Request $request): Response
    {
        $file = $this->files->findByTokenOrAlias($token);

        if (!$file || $file->isExpired()) {
            throw $this->createNotFoundException('Soubor neexistuje nebo vypršela jeho platnost.');
        }

        // Soubor chráněný heslem
        if ($file->isPasswordProtected()) {
            $submittedPassword = $request->request->get('password');

            if (!$submittedPassword || !password_verify($submittedPassword, $file->getPasswordHash())) {
                return $this->render('share/password.html.twig', [
                    'file'  => $file,
                    'error' => $submittedPassword !== null ? 'Nesprávné heslo.' : null,
                ]);
            }

            $request->getSession()->set('unlocked_' . $file->getShareToken(), true);
        }

        // Soubor infikován virem
        if ($file->isInfected()) {
            return $this->render('share/infected.html.twig', ['file' => $file]);
        }

        // Preview URL (jen pro čisté soubory — pending zobrazíme jako waiting)
        $previewUrl = null;
        if ($file->isClean()) {
            $mime = $file->getMimeType() ?? '';
            if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
                $previewUrl = $this->generateUrl('share_preview', ['token' => $file->getShareToken()]);
            }
        }

        return $this->render('share/show.html.twig', [
            'file'       => $file,
            'expiration' => $this->expiration,
            'previewUrl' => $previewUrl,
        ]);
    }

    /** Stav skenování — pro polling z Alpine.js */
    #[Route('/s/{token}/status', name: 'share_status', methods: ['GET'])]
    public function status(string $token): JsonResponse
    {
        $file = $this->files->findByTokenOrAlias($token);

        if (!$file || $file->isExpired()) {
            return $this->json(['status' => 'not_found'], 404);
        }

        return $this->json([
            'status'     => $file->getScanStatus(),
            'previewUrl' => ($file->isClean() && (
                str_starts_with($file->getMimeType() ?? '', 'image/') ||
                str_starts_with($file->getMimeType() ?? '', 'video/')
            )) ? $this->generateUrl('share_preview', ['token' => $file->getShareToken()]) : null,
        ]);
    }

    /** Proxy preview — streamuje obrázek/video přes Symfony (žádné CORS) */
    #[Route('/s/{token}/preview', name: 'share_preview', methods: ['GET'])]
    public function preview(string $token, Request $request): Response
    {
        $file = $this->files->findByShareToken($token);

        if (!$file || $file->isExpired() || !$file->isClean()) {
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

    /** Samotné stažení */
    #[Route('/s/{token}/download', name: 'share_download', methods: ['GET'])]
    public function download(string $token, Request $request): Response
    {
        $file = $this->files->findByTokenOrAlias($token);

        if (!$file || $file->isExpired()) {
            throw $this->createNotFoundException('Soubor neexistuje nebo vypršela jeho platnost.');
        }

        // Blokovat stažení infikovaných a ještě neskenovaných souborů
        if ($file->isInfected()) {
            return $this->render('share/infected.html.twig', ['file' => $file]);
        }

        if ($file->isScanPending()) {
            return $this->redirectToRoute('share_show', ['token' => $token]);
        }

        if ($file->isPasswordProtected()) {
            if (!$request->getSession()->get('unlocked_' . $file->getShareToken(), false)) {
                return $this->redirectToRoute('share_show', ['token' => $token]);
            }
        }

        $file->incrementDownloadCount();
        $this->em->flush();

        return $this->redirect($this->minio->getPresignedUrl($file->getMinioKey(), 3600));
    }
}
