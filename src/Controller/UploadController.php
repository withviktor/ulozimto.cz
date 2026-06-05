<?php

namespace App\Controller;

use App\Entity\File;
use App\Service\FileExpirationService;
use App\Service\MinioService;
use App\Service\ZipService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/upload')]
class UploadController extends AbstractController
{
    public function __construct(
        private readonly MinioService           $minio,
        private readonly ZipService             $zip,
        private readonly FileExpirationService  $expiration,
        private readonly EntityManagerInterface $em,
        private readonly Security               $security,
    ) {}

    // ----------------------------------------------------------------
    // Hlavní stránka s formulářem
    // ----------------------------------------------------------------

    #[Route('', name: 'upload_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('upload/index.html.twig', [
            'allowedDays' => FileExpirationService::ALLOWED_DAYS,
            'defaultDays' => FileExpirationService::DEFAULT_DAYS,
        ]);
    }

    // ----------------------------------------------------------------
    // Krok 1: Inicializace multipart uploadu v MinIO
    // ----------------------------------------------------------------

    #[Route('/init', name: 'upload_init', methods: ['POST'])]
    public function init(Request $request): JsonResponse
    {
        $data     = json_decode($request->getContent(), true);
        $filename = $data['filename'] ?? 'soubor';
        $mime     = $data['mimeType'] ?? 'application/octet-stream';

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
    // Krok 3: Dokončení uploadu + uložení metadat do DB
    // ----------------------------------------------------------------

    #[Route('/complete', name: 'upload_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $uploadId    = $data['uploadId']    ?? null;
        $minioKey    = $data['minioKey']    ?? null;
        $parts       = $data['parts']       ?? [];
        $filename    = $data['filename']    ?? 'soubor';
        $mime        = $data['mimeType']    ?? 'application/octet-stream';
        $size        = (int) ($data['size'] ?? 0);
        $expireDays  = (int) ($data['expireDays'] ?? FileExpirationService::DEFAULT_DAYS);
        $password    = $data['password']    ?? null;

        if (!$uploadId || !$minioKey || empty($parts)) {
            return $this->json(['error' => 'Chybějí data pro dokončení uploadu'], 400);
        }

        // Seřadit části dle partNumber
        usort($parts, fn($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        $this->minio->completeMultipartUpload($minioKey, $uploadId, $parts);

        // Uložit záznam do DB
        $file = new File();
        $file->setShareToken($this->generateToken());
        $file->setOriginalName($filename);
        $file->setMimeType($mime);
        $file->setSizeBytes($size);
        $file->setMinioKey($minioKey);
        $file->setExpiresAt($this->expiration->getExpirationDate($expireDays));

        if ($password) {
            $file->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->security->getUser();
        if ($user) {
            $file->setUser($user);
        }

        $this->em->persist($file);
        $this->em->flush();

        return $this->json([
            'shareUrl' => $this->generateUrl('share_show', ['token' => $file->getShareToken()]),
            'token'    => $file->getShareToken(),
        ]);
    }

    // ----------------------------------------------------------------
    // Upload složky: server složku zazipuje a nahraje jako jeden soubor
    // ----------------------------------------------------------------

    #[Route('/folder', name: 'upload_folder', methods: ['POST'])]
    public function folder(Request $request): JsonResponse
    {
        $files      = $request->files->get('files', []);
        $names      = $request->request->all('names');   // relativní cesty souborů
        $archiveName = $request->request->get('archiveName', 'archiv.zip');
        $expireDays = (int) $request->request->get('expireDays', FileExpirationService::DEFAULT_DAYS);
        $password   = $request->request->get('password');

        if (empty($files)) {
            return $this->json(['error' => 'Žádné soubory'], 400);
        }

        // Připravit pole pro ZipService
        $fileMap = [];
        foreach ($files as $i => $uploadedFile) {
            $fileMap[] = [
                'tmpPath' => $uploadedFile->getPathname(),
                'name'    => $names[$i] ?? $uploadedFile->getClientOriginalName(),
            ];
        }

        $zipPath = $this->zip->createZip($fileMap, $archiveName);

        try {
            $minioKey = $this->minio->generateKey($archiveName);
            $size     = filesize($zipPath);

            $this->minio->putObject(
                $minioKey,
                fopen($zipPath, 'rb'),
                'application/zip'
            );
        } finally {
            @unlink($zipPath);
        }

        $file = new File();
        $file->setShareToken($this->generateToken());
        $file->setOriginalName($archiveName);
        $file->setMimeType('application/zip');
        $file->setSizeBytes($size ?: 0);
        $file->setMinioKey($minioKey);
        $file->setExpiresAt($this->expiration->getExpirationDate($expireDays));

        if ($password) {
            $file->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));
        }

        $user = $this->security->getUser();
        if ($user) {
            $file->setUser($user);
        }

        $this->em->persist($file);
        $this->em->flush();

        return $this->json([
            'shareUrl' => $this->generateUrl('share_show', ['token' => $file->getShareToken()]),
            'token'    => $file->getShareToken(),
        ]);
    }

    // ----------------------------------------------------------------

    private function generateToken(int $length = 10): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $token;
    }
}
