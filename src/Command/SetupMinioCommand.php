<?php

namespace App\Command;

use App\Service\MinioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:setup-minio',
    description: 'Vytvoří MinIO bucket a nastaví CORS politiku',
)]
class SetupMinioCommand extends Command
{
    public function __construct(private readonly MinioService $minio)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('MinIO setup');

        $this->minio->ensureBucketExists();

        $io->success('Bucket vytvořen a CORS nastaven.');
        return Command::SUCCESS;
    }
}
