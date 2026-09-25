<?php

declare(strict_types=1);

namespace Acme\AcmeBundle\Command;

use Acme\AcmeBundle\Message\SyncOrdersToErp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** The same sync from the command line, or from a schedule. */
#[AsCommand('acme:erp:sync', 'Queue a sync of orders to the ERP.')]
final readonly class SyncOrdersToErpCommand
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function __invoke(OutputInterface $output): int
    {
        $this->bus->dispatch(new SyncOrdersToErp('cli'));
        $output->writeln('ERP sync queued.');

        return Command::SUCCESS;
    }
}
