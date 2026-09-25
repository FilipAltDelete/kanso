<?php

declare(strict_types=1);

namespace Kanso\Cli;

use Kanso\Application\User\UserService;
use Kanso\Domain\User\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run after migrations on every start; idempotent. Creates the first
 * administrator when the installation has no users.
 */
#[AsCommand('kanso:install', 'Prepare a fresh installation: create the first administrator.')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly UserService $users,
        private readonly string $adminEmail,
        private readonly string $adminPassword,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->users->hasAnyUser()) {
            $output->writeln('Users exist; nothing to install.');

            return Command::SUCCESS;
        }

        if ('prod' === $this->environment && ('' === $this->adminEmail || '' === $this->adminPassword)) {
            $output->writeln('<error>Set KANSO_ADMIN_EMAIL and KANSO_ADMIN_PASSWORD: the admin / admin default is for development only.</error>');

            return Command::FAILURE;
        }

        $email = '' === $this->adminEmail ? 'admin' : $this->adminEmail;
        $this->users->create($email, '' === $this->adminPassword ? 'admin' : $this->adminPassword, [Role::ADMIN], 'Administrator');
        $output->writeln(\sprintf('Created the administrator "%s".', $email));

        return Command::SUCCESS;
    }
}
