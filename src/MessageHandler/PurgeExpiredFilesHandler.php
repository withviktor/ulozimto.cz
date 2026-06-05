<?php

namespace App\MessageHandler;

use App\Message\PurgeExpiredFilesMessage;
use App\Repository\FileRepository;
use App\Service\MinioService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PurgeExpiredFilesHandler
{
    public function __construct(
        private readonly FileRepository         $files,
        private readonly MinioService           $minio,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(PurgeExpiredFilesMessage $message): void
    {
        $expired = $this->files->findExpired();

        if (empty($expired)) {
            return;
        }

        $count = 0;
        foreach ($expired as $file) {
            try {
                $this->minio->delete($file->getMinioKey());
            } catch (\Throwable $e) {
                $this->logger->warning('Nelze smazat soubor z MinIO: ' . $file->getMinioKey(), [
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->em->remove($file);
            $count++;
        }

        $this->em->flush();
        $this->logger->info("Smazáno {$count} expirovaných souborů.");
    }
}
