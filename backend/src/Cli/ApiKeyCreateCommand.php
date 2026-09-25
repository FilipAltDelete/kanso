<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Cli;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Security\ApiKeyService;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('kanso:api-key:create', 'Create an API key for an integration. The key is printed once.')]
final class ApiKeyCreateCommand extends Command
{
    public function __construct(private readonly ApiKeyService $apiKeys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'What the key is for, e.g. "Shopify sync"')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'One of '.Role::OPERATOR.', '.Role::VIEWER, Role::VIEWER)
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'When the key stops working, e.g. "2027-01-01" or "+90 days" (UTC)')
            ->addOption('created-by', null, InputOption::VALUE_REQUIRED, 'Email or id of the user the key is recorded as created by');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expires = $input->getOption('expires');
        $createdBy = $input->getOption('created-by');

        $expiresAt = null;
        if (\is_string($expires) && '' !== $expires) {
            try {
                $expiresAt = new \DateTimeImmutable($expires, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                $output->writeln(\sprintf('<error>expires: "%s" is not a date.</error>', $expires));

                return Command::FAILURE;
            }
        }

        try {
            ['key' => $key, 'plainKey' => $plainKey] = $this->apiKeys->create(
                (string) $input->getArgument('name'),
                (string) $input->getOption('role'),
                $expiresAt,
                \is_string($createdBy) ? $createdBy : null,
            );
        } catch (ValidationFailed $e) {
            foreach ($e->violations() as $violation) {
                $output->writeln(\sprintf('<error>%s: %s</error>', $violation['path'], $violation['message']));
            }

            return Command::FAILURE;
        }

        $output->writeln(\sprintf('Created API key "%s" (%s) with %s.', $key->name(), (string) $key->id(), $key->role()));
        $output->writeln('Store it now; it cannot be shown again:');
        $output->writeln('');
        $output->writeln($plainKey);

        return Command::SUCCESS;
    }
}
