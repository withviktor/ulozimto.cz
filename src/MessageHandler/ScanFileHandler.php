<?php

namespace App\MessageHandler;

use App\Entity\File;
use App\Message\ScanFileMessage;
use App\Repository\FileRepository;
use App\Service\ClamAvService;
use App\Service\MinioService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ScanFileHandler
{
    public function __construct(
        private readonly FileRepository         $files,
        private readonly ClamAvService          $clamAv,
        private readonly MinioService           $minio,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(ScanFileMessage $message): void
    {
        $file = $this->files->find($message->fileId);

        if (!$file) {
            $this->logger->warning("ScanFileHandler: soubor {$message->fileId} nenalezen.");
            return;
        }

        // Přeskočit pokud soubor mezitím expiroval nebo byl smazán
        if ($file->isExpired()) {
            $file->setScanStatus(File::SCAN_ERROR);
            $this->em->flush();
            return;
        }

        try {
            $stream = $this->minio->getObjectStream($file->getMinioKey());
            $result = $this->clamAv->scanStream($stream);

            match ($result) {
                'infected' => $this->handleInfected($file),
                'clean'    => $file->setScanStatus(File::SCAN_CLEAN),
                default    => $this->handleError($file, 'Neznámý výsledek: ' . $result),
            };

        } catch (\Throwable $e) {
            $this->handleError($file, $e->getMessage());
        }

        $this->em->flush();
    }

    private function handleInfected(File $file): void
    {
        $this->logger->warning("ClamAV: infikovaný soubor nalezen: {$file->getMinioKey()}");

        try {
            $this->minio->delete($file->getMinioKey());
        } catch (\Throwable $e) {
            $this->logger->error("Nelze smazat infikovaný soubor z MinIO: {$e->getMessage()}");
        }

        $file->setScanStatus(File::SCAN_INFECTED);
    }

    private function handleError(File $file, string $reason): void
    {
        $this->logger->error("ClamAV sken selhal pro {$file->getMinioKey()}: {$reason}");

        // Fail open — soubor zpřístupníme i při selhání skeneru
        // Změň na SCAN_ERROR pokud chceš fail closed chování
        $file->setScanStatus(File::SCAN_CLEAN);
    }
}
