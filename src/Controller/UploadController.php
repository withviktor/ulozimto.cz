<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Message\ScanFileMessage;
use App\Repository\FileRepository;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use App\Service\ZipService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/upload')]
class UploadController extends AbstractController
{
    public function __construct(
        private readonly MinioService           $minio,
        private readonly ZipService             $zip,
        private readonly FileExpirationService  $expiration,
        private readonly FileRepository         $fileRepo,
        private readonly EntityManagerInterface $em,
        private readonly Security               $security,
        private readonly MessageBusInterface    $bus,
        private readonly RateLimiterFactory     $uploadLimiter,
    ) {}

    // ----------------------------------------------------------------
    // Hlavní stránka s formulářem
    // ----------------------------------------------------------------

    #[Route('', name: 'upload_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User|null $user */
        $user = $this->security->getUser();

        $storageUsed  = $user ? $this->fileRepo->getTotalStorageForUser((string) $user->getId()) : null;
        $storageLimit = $user?->getStorageLimit();

        return $this->render('upload/index.html.twig', [
            'expirationOptions' => $this->expiration->getOptions($user),
            'defaultHours'      => $this->expiration->getDefaultHours($user),
            'fileSizeLimit'     => $user ? $user->getFileSizeLimit() : User::LIMIT_FILE_ANONYMOUS,
            'isPlus'            => $user?->isPlus() ?? false,
            'storageUsed'       => $storageUsed,
            'storageLimit'      => $storageLimit,
        ]);
    }

    // ----------------------------------------------------------------
    // Krok 1: Inicializace multipart uploadu — early size check
    // ----------------------------------------------------------------

    #[Route('/init', name: 'upload_init', methods: ['POST'])]
    public function init(Request $request): JsonResponse
    {
        // Rate limit: 50 nahrání za hodinu z jedné IP
        $limiter = $this->uploadLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json([
                'error' => 'Příliš mnoho nahrávání. Zkus to za hodinu.',
                'code'  => 'RATE_LIMITED',
            ], 429);
        }

        $data          = json_decode($request->getContent(), true);
        $filename      = $data['filename']     ?? 'soubor';
        $mime          = $data['mimeType']      ?? 'application/octet-stream';
        $declaredSize  = (int) ($data['size']   ?? 0);

        /** @var User|null $user */
        $user      = $this->security->getUser();
        $sizeLimit = $user ? $user->getFileSizeLimit() : User::LIMIT_FILE_ANONYMOUS;

        if ($declaredSize > 0 && $declaredSize > $sizeLimit) {
            $limitLabel = $this->formatBytes($sizeLimit);
            return $this->json([
                'error' => "Soubor je příliš velký. Maximální velikost pro tvůj plán je {$limitLabel}.",
                'code'  => 'FILE_TOO_LARGE',
            ], 413);
        }

        $key      = $this->minio->generateKey($filename);
        $uploadId = $this->minio->createMultipartUpload($key, $mime);

        return $this->json([
            'uploadId' => $uploadId,
            'minioKey' => $key,
        ]);
    }

    // ----------------------------------------------------------------
    // Krok 2: Nahrání jednoho chunku
    // ----------------------------------------------------------------

    #[Route('/chunk', name: 'upload_chunk', methods: ['POST'])]
    public function chunk(Request $request): JsonResponse
    {
        $uploadId   = $request->request->get('uploadId');
        $minioKey   = $request->request->get('minioKey');
        $partNumber = (int) $request->request->get('partNumber');
        $chunkFile  = $request->files->get('chunk');

        if (!$chunkFile || !$uploadId || !$minioKey || $partNumber < 1) {
            return $this->json(['error' => 'Neplatná data chunku'], 400);
        }

        $etag = $this->minio->uploadPart(
            $minioKey,
            $uploadId,
            $partNumber,
            fopen($chunkFile->getPathname(), 'rb')
        );

        return $this->json(['etag' => $etag, 'partNumber' => $partNumber]);
    }

    // ----------------------------------------------------------------
    // Krok 3: Dokončení uploadu + uložení metadat + dispatch skenování
    // ----------------------------------------------------------------

    #[Route('/complete', name: 'upload_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $uploadId    = $data['uploadId']     ?? null;
        $minioKey    = $data['minioKey']     ?? null;
        $parts       = $data['parts']        ?? [];
        $filename    = $data['filename']     ?? 'soubor';
        $mime        = $data['mimeType']     ?? 'application/octet-stream';
        $size        = (int) ($data['size']  ?? 0);
        $expireHours = (int) ($data['expireHours'] ?? 0);
        $password    = $data['password']     ?? null;
        $customAlias = trim($data['customAlias'] ?? '');

        if (!$uploadId || !$minioKey || empty($parts)) {
            return $this->json(['error' => 'Chybějí data pro dokončení uploadu'], 400);
        }

        /** @var User|null $user */
        $user = $this->security->getUser();

        // --- Kontrola velikosti souboru ---
        $sizeLimit = $user ? $user->getFileSizeLimit() : User::LIMIT_FILE_ANONYMOUS;
        if ($size > $sizeLimit) {
            $this->minio->abortMultipartUpload($minioKey, $uploadId);
            return $this->json([
                'error' => 'Soubor překračuje povolenou velikost pro tvůj plán.',
                'code'  => 'FILE_TOO_LARGE',
            ], 413);
        }

        // --- Kontrola celkového úložiště (jen přihlášení uživatelé) ---
        if ($user) {
            $used  = $this->fileRepo->getTotalStorageForUser((string) $user->getId());
            $limit = $user->getStorageLimit();
            if ($used + $size > $limit) {
                $this->minio->abortMultipartUpload($minioKey, $uploadId);
                return $this->json([
                    'error' => 'Překročen limit celkového úložiště tvého plánu.',
                    'code'  => 'STORAGE_FULL',
                ], 413);
            }
        }

        // --- Vlastní alias — jen PLUS ---
        if ($customAlias !== '' && !($user?->isPlus())) {
            $customAlias = '';
        }

        usort($parts, fn($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
        $this->minio->completeMultipartUpload($minioKey, $uploadId, $parts);

        $file = $this->createFileEntity($user, $filename, $mime, $size, $minioKey, $expireHours, $password);

        if ($customAlias !== '') {
            $file->setCustomAlias($customAlias);
        }

        $this->em->persist($file);
        $this->em->flush();

        $this->dispatchScan($file, $user);

        $token = $file->getCustomAlias() ?? $file->getShareToken();

        return $this->json([
            'shareUrl' => $this->generateUrl('share_show', ['token' => $token]),
            'token'    => $token,
        ]);
    }

    // ----------------------------------------------------------------
    // Upload složky: server složku zazipuje a nahraje jako jeden soubor
    // ----------------------------------------------------------------

    #[Route('/folder', name: 'upload_folder', methods: ['POST'])]
    public function folder(Request $request): JsonResponse
    {
        $files       = $request->files->get('files', []);
        $names       = $request->request->all('names');
        $archiveName = $request->request->get('archiveName', 'archiv.zip');
        $expireHours = (int) $request->request->get('expireHours', 0);
        $password    = $request->request->get('password');
        $customAlias = trim($request->request->get('customAlias', ''));

        if (empty($files)) {
            return $this->json(['error' => 'Žádné soubory'], 400);
        }

        /** @var User|null $user */
        $user = $this->security->getUser();

        $fileMap = [];
        foreach ($files as $i => $uploadedFile) {
            $fileMap[] = [
                'tmpPath' => $uploadedFile->getPathname(),
                'name'    => $names[$i] ?? $uploadedFile->getClientOriginalName(),
            ];
        }

        $zipPath = $this->zip->createZip($fileMap, $archiveName);

        try {
            $size = filesize($zipPath) ?: 0;

            // Kontrola velikosti ZIP archivu
            $sizeLimit = $user ? $user->getFileSizeLimit() : User::LIMIT_FILE_ANONYMOUS;
            if ($size > $sizeLimit) {
                return $this->json([
                    'error' => 'Archiv překračuje povolenou velikost pro tvůj plán.',
                    'code'  => 'FILE_TOO_LARGE',
                ], 413);
            }

            // Kontrola celkového úložiště
            if ($user) {
                $used = $this->fileRepo->getTotalStorageForUser((string) $user->getId());
                if ($used + $size > $user->getStorageLimit()) {
                    return $this->json([
                        'error' => 'Překročen limit celkového úložiště tvého plánu.',
                        'code'  => 'STORAGE_FULL',
                    ], 413);
                }
            }

            $minioKey = $this->minio->generateKey($archiveName);
            $this->minio->putObject($minioKey, fopen($zipPath, 'rb'), 'application/zip');
        } finally {
            @unlink($zipPath);
        }

        if ($customAlias !== '' && !($user?->isPlus())) {
            $customAlias = '';
        }

        $file = $this->createFileEntity($user, $archiveName, 'application/zip', $size, $minioKey, $expireHours, $password);

        if ($customAlias !== '') {
            $file->setCustomAlias($customAlias);
        }

        $this->em->persist($file);
        $this->em->flush();

        $this->dispatchScan($file, $user);

        $token = $file->getCustomAlias() ?? $file->getShareToken();

        return $this->json([
            'shareUrl' => $this->generateUrl('share_show', ['token' => $token]),
            'token'    => $token,
        ]);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function createFileEntity(
        ?User $user,
        string $filename,
        string $mime,
        int $size,
        string $minioKey,
        int $expireHours,
        ?string $password,
    ): File {
        $file = new File();
        $file->setShareToken($this->generateToken());
        $file->setOriginalName($filename);
        $file->setMimeType($mime);
        $file->setSizeBytes($size);
        $file->setMinioKey($minioKey);
        $file->setExpiresAt($this->expiration->getExpirationDate($expireHours, $user));
        $file->setScanStatus(File::SCAN_PENDING);

        if ($password) {
            $file->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));
        }

        if ($user) {
            $file->setUser($user);
        }

        return $file;
    }

    private function dispatchScan(File $file, ?User $user): void
    {
        $transport = ($user?->isPlus()) ? 'scan_priority' : 'scan_default';

        $this->bus->dispatch(
            new Envelope(
                new ScanFileMessage($file->getId()->toRfc4122()),
                [new TransportNamesStamp([$transport])]
            )
        );
    }

    private function generateToken(int $length = 10): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $token;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024)          return $bytes . ' B';
        if ($bytes < 1_048_576)     return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1_073_741_824) return round($bytes / 1_048_576, 1) . ' MB';
        return round($bytes / 1_073_741_824, 2) . ' GB';
    }
}
