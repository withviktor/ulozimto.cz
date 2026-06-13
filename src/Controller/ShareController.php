<?php

namespace App\Controller;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use App\Service\PasswordVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class ShareController extends AbstractController
{
    /** MIME typy podporující inline preview */
    private const PREVIEWABLE = [
        'image/', 'video/', 'audio/', 'text/',
    ];
    private const PREVIEWABLE_EXACT = [
        'application/pdf',
        'application/json',
    ];

    public function __construct(
        private readonly FileRepository              $files,
        private readonly MinioService                $minio,
        private readonly FileExpirationService       $expiration,
        private readonly EntityManagerInterface      $em,
        private readonly PasswordVerificationService $passwordVerification,
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
            // Zkontrolovat, zda je soubor již odemčen
            if (!$this->passwordVerification->isUnlocked($file, $request)) {
                $submittedPassword = $request->request->get('password');

                // Pokud byl zadán nový pokus
                if ($submittedPassword !== null) {
                    if (!$this->passwordVerification->verifyAndUnlock($file, $submittedPassword, $request)) {
                        return $this->render('share/password.html.twig', [
                            'file'  => $file,
                            'error' => 'Nesprávné heslo.',
                        ]);
                    }
                } else {
                    // Heslo zatím zadáno nebylo, zobrazit formulář
                    return $this->render('share/password.html.twig', [
                        'file'  => $file,
                        'error' => null,
                    ]);
                }
            }
        }

        // Soubor infikován virem
        if ($file->isInfected()) {
            return $this->render('share/infected.html.twig', ['file' => $file]);
        }

        $previewUrl = $file->isClean() ? $this->buildPreviewUrl($file) : null;

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

        // SECURITY FIX: Hide password-protected files from status endpoint
        // EXCEPTION: File owners can bypass password check
        if ($file->isPasswordProtected()) {
            $user = $this->getUser();
            // Only allow owners to check status without password
            if (!$user || $file->getUser() !== $user) {
                return $this->json(['status' => 'not_found'], 404);
            }
        }

        return $this->json([
            'status'     => $file->getScanStatus(),
            'previewUrl' => $file->isClean() ? $this->buildPreviewUrl($file) : null,
        ]);
    }

    /** Proxy preview — streamuje soubor přes Symfony (žádné CORS) */
    #[Route('/s/{token}/preview', name: 'share_preview', methods: ['GET'])]
    public function preview(string $token, Request $request): Response
    {
        $file = $this->files->findByShareToken($token);

        if (!$file || $file->isExpired() || !$file->isClean()) {
            throw $this->createNotFoundException();
        }

        // Ověřit heslo - s defense-in-depth přístupem
        if ($file->isPasswordProtected()) {
            if (!$this->passwordVerification->isUnlocked($file, $request)) {
                return new Response('Unauthorized - password required', 401);
            }
        }

        // PHP session soubor je zamčen po celou dobu trvání požadavku.
        // StreamedResponse může běžet minuty (video) — jakýkoliv další požadavek
        // ze stejného prohlížeče (refresh, polling, navigace) by čekal na
        // uvolnění session zámku a "zamrzl". save() zámek okamžitě uvolní.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $mime     = $file->getMimeType() ?? 'application/octet-stream';
        $fileSize = $file->getSizeBytes();

        // ── Video / Audio: proxy s podporou HTTP Range požadavků ──────
        //
        // Redirect na presigned MinIO URL NEFUNGUJE, pokud je stránka na HTTPS
        // a MinIO běží na plain HTTP — prohlížeč blokuje mixed content pro
        // vložené <video>/<audio> prvky.
        //
        // Správné řešení: proxy přes Symfony + implementovat Range požadavky
        // (RFC 7233). Prohlížeč pošle "Range: bytes=X-Y" při hledání.
        // Bez Range podpory dostane 200 místo 206 → zahazuje buffer a začíná
        // znovu od bytu 0 → nekonečná smyčka požadavků → lag.
        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            $rangeHeader = $request->headers->get('Range', '');

            // Zpracovat Range: bytes=START-[END]
            if ($rangeHeader && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $m)) {
                $start  = (int) $m[1];
                $end    = $m[2] !== '' ? (int) $m[2] : $fileSize - 1;
                $end    = min($end, $fileSize - 1);
                $length = max(0, $end - $start + 1);

                $stream = $this->minio->getObjectStream($file->getMinioKey(), $start, $end);

                $response = new StreamedResponse(function () use ($stream): void {
                    $this->pipe($stream);
                }, 206);

                $response->headers->set('Content-Type',   $mime);
                $response->headers->set('Content-Length', (string) $length);
                $response->headers->set('Content-Range',  "bytes {$start}-{$end}/{$fileSize}");
                $response->headers->set('Accept-Ranges',  'bytes');
                $response->headers->set('Cache-Control',  'private, max-age=0');

                return $response;
            }

            // Celý soubor (první požadavek bez Range)
            $stream = $this->minio->getObjectStream($file->getMinioKey());

            $response = new StreamedResponse(function () use ($stream): void {
                $this->pipe($stream);
            });

            $response->headers->set('Content-Type',   $mime);
            $response->headers->set('Content-Length', (string) $fileSize);
            $response->headers->set('Accept-Ranges',  'bytes');
            $response->headers->set('Cache-Control',  'private, max-age=0');

            return $response;
        }

        // ── Ostatní typy (obrázky, PDF, text) — jednoduchý proxy ─────
        $stream = $this->minio->getObjectStream($file->getMinioKey());

        $response = new StreamedResponse(function () use ($stream): void {
            $this->pipe($stream);
        });

        $response->headers->set('Content-Type',   $mime);
        $response->headers->set('Cache-Control',  'private, max-age=300');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($mime === 'application/pdf' || str_starts_with($mime, 'text/')) {
            $response->headers->set('Content-Disposition', 'inline');
        }

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

        if ($file->isInfected()) {
            return $this->render('share/infected.html.twig', ['file' => $file]);
        }

        if ($file->isScanPending()) {
            return $this->redirectToRoute('share_show', ['token' => $token]);
        }

        // Ověřit heslo - s defense-in-depth přístupem
        if ($file->isPasswordProtected()) {
            if (!$this->passwordVerification->isUnlocked($file, $request)) {
                return $this->redirectToRoute('share_show', ['token' => $token]);
            }
        }

        $file->incrementDownloadCount();
        $this->em->flush();

        return $this->redirect($this->minio->getPresignedUrl($file->getMinioKey(), 3600));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Streamuje obsah PHP streamu na výstup po částech (256 KB).
     *
     * Na rozdíl od fpassthru() kontroluje po každém chunku, zda klient stále
     * poslouchá. Jakmile prohlížeč zavře spojení (uživatel zastaví audio/video,
     * zavře záložku, refreshne stránku), connection_aborted() vrátí true a smyčka
     * okamžitě skončí — FPM worker se uvolní místo toho, aby čekal na timeout.
     *
     * @param resource $stream
     */
    private function pipe(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }

        ignore_user_abort(false);          // detekovat odpojení klienta
        $chunkSize = 256 * 1_024;          // 256 KB na iteraci

        while (!feof($stream) && !connection_aborted()) {
            $chunk = fread($stream, $chunkSize);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
        }

        fclose($stream);
    }

    private function buildPreviewUrl(File $file): ?string
    {
        $mime = $file->getMimeType() ?? '';

        foreach (self::PREVIEWABLE as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return $this->generateUrl('share_preview', ['token' => $file->getShareToken()]);
            }
        }

        if (in_array($mime, self::PREVIEWABLE_EXACT, true)) {
            return $this->generateUrl('share_preview', ['token' => $file->getShareToken()]);
        }

        return null;
    }
}
