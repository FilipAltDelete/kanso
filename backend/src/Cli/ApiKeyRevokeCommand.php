<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Cli;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Security\ApiKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('kanso:api-key:revoke', 'Revoke an API key; requests made with it fail from now on.')]
final class ApiKeyRevokeCommand extends Command
{
    public function __construct(private readonly ApiKeyService $apiKeys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The key id printed when it was created');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $key = $this->apiKeys->revoke((string) $input->getArgument('id'));
        } catch (ValidationFailed $e) {
            foreach ($e->violations() as $violation) {
                $output->writeln(\sprintf('<error>%s: %s</error>', $violation['path'], $violation['message']));
            }

            return Command::FAILURE;
        }

        $output->writeln(\sprintf('Revoked API key "%s" (%s).', $key->name(), (string) $key->id()));

        return Command::SUCCESS;
    }
}
