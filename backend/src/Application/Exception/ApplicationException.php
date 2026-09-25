<?php

declare(strict_types=1);

namespace Kanso\Application\Exception;

/**
 * A failure the caller can act on. The API turns every one of these into an
 * RFC 7807 problem response (Api\EventListener\ApplicationExceptionListener).
 */
abstract class ApplicationException extends \RuntimeException
{
    /** @param list<array{path: string, message: string, code: string}> $violations */
    public function __construct(
        string $detail,
        private readonly array $violations = [],
    ) {
        parent::__construct($detail);
    }

    abstract public function status(): int;

    abstract public function title(): string;

    /** @return list<array{path: string, message: string, code: string}> */
    public function violations(): array
    {
        return $this->violations;
    }
}
