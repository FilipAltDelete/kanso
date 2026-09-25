<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Cli;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('kanso:user:create', 'Create a user who can sign in to the web UI.')]
final class UserCreateCommand extends Command
{
    public function __construct(private readonly UserService $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'One of '.implode(', ', Role::ALL), [Role::OPERATOR])
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $roles */
        $roles = $input->getOption('role');
        $name = $input->getOption('name');

        try {
            $user = $this->users->create(
                (string) $input->getArgument('email'),
                (string) $input->getArgument('password'),
                $roles,
                \is_string($name) ? $name : null,
            );
        } catch (ValidationFailed $e) {
            foreach ($e->violations() as $violation) {
                $output->writeln(\sprintf('<error>%s: %s</error>', $violation['path'], $violation['message']));
            }

            return Command::FAILURE;
        }

        $output->writeln(\sprintf('Created %s (%s).', $user->email(), (string) $user->id()));

        return Command::SUCCESS;
    }
}
