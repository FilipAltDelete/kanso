<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Cli;

use Kanso\Core\Internal\Domain\Health\HealthCheckInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** The container healthcheck: exits non-zero when a dependency is down. */
#[AsCommand('kanso:health', 'Check that the database and Redis answer.')]
final class HealthCommand extends Command
{
    public function __construct(private readonly HealthCheckInterface $health)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $healthy = true;
        foreach ($this->health->check() as $name => $status) {
            $output->writeln(\sprintf('%-10s %s', $name, $status));
            $healthy = $healthy && 'ok' === $status;
        }

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
