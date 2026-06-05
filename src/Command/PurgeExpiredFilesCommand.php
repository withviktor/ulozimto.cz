<?php

namespace App\Command;

use App\Message\PurgeExpiredFilesMessage;
use App\MessageHandler\PurgeExpiredFilesHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-expired',
    description: 'Smaže všechny expirované soubory z MinIO a databáze',
)]
class PurgeExpiredFilesCommand extends Command
{
    public function __construct(private readonly PurgeExpiredFilesHandler $handler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Mazání expirovaných souborů');

        ($this->handler)(new PurgeExpiredFilesMessage());

        $io->success('Hotovo.');
        return Command::SUCCESS;
    }
}
