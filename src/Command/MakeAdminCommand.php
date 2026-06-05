<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:make-admin',
    description: 'Přidělí ROLE_ADMIN zadanému uživateli.'
)]
class MakeAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'E-mail uživatele');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $io->error("Uživatel '{$email}' nebyl nalezen.");
            return Command::FAILURE;
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $io->warning("Uživatel '{$email}' již má ROLE_ADMIN.");
            return Command::SUCCESS;
        }

        $user->setRoles(array_unique([...$roles, 'ROLE_ADMIN']));
        $this->em->flush();

        $io->success("Uživatel '{$email}' byl povýšen na administrátora.");
        return Command::SUCCESS;
    }
}
