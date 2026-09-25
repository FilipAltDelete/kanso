<?php

declare(strict_types=1);

namespace Kanso\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a fresh signing keypair as environment variables. Keys reach the
 * containers the same way every other secret does — as environment variables —
 * so they are base64 encoded here to survive a single line.
 */
#[AsCommand('kanso:jwt:generate-keys', 'Print a new JWT signing keypair as environment variables.')]
final class GenerateJwtKeysCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('bits', null, InputOption::VALUE_REQUIRED, 'RSA key size', '2048');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = openssl_pkey_new([
            'private_key_bits' => max(2048, (int) $input->getOption('bits')),
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);

        if (false === $key || !openssl_pkey_export($key, $privateKey)) {
            $output->writeln('<error>Could not generate a keypair; is the OpenSSL extension configured?</error>');

            return Command::FAILURE;
        }

        $details = openssl_pkey_get_details($key);
        if (false === $details) {
            $output->writeln('<error>Could not read the generated public key.</error>');

            return Command::FAILURE;
        }

        $output->writeln('JWT_SECRET_KEY='.base64_encode($privateKey));
        $output->writeln('JWT_PUBLIC_KEY='.base64_encode((string) $details['key']));

        return Command::SUCCESS;
    }
}
