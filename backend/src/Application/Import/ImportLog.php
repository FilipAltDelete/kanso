<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Import;

use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Import\ImportRun;
use Kanso\Core\Internal\Domain\Import\ImportRunStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Records every import that was run for real (ADR-0014), after it has run:
 * the import's own transactions are committed by then, so the record is of
 * what actually happened. Previews are not recorded.
 */
final class ImportLog
{
    public function __construct(
        private readonly ImportRunStoreInterface $runs,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, int>         $counts the import's counts, as its result names them
     * @param list<array<string, mixed>> $errors the rows that failed, as its result lists them
     */
    public function record(string $type, ?string $filename, Actor $actor, array $counts, array $errors): ImportRun
    {
        $run = new ImportRun($type, self::filename($filename), $actor, $counts, $errors, $this->clock->now());
        $this->transaction->run(fn () => $this->runs->add($run));

        return $run;
    }

    /** The name as the browser gave it, without any path, at most 255 characters; null when there is none. */
    private static function filename(?string $filename): ?string
    {
        $name = trim(basename(str_replace('\\', '/', (string) $filename)));

        return '' === $name ? null : mb_substr($name, 0, 255);
    }
}
