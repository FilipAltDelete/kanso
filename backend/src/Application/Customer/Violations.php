<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Customer;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;

/** Collects every problem with an input, so the caller hears about all of them at once. */
final class Violations implements \Countable
{
    /** @var list<array{path: string, message: string, code: string}> */
    private array $violations = [];

    public function add(string $path, string $message, string $code): void
    {
        $this->violations[] = ['path' => $path, 'message' => $message, 'code' => $code];
    }

    /** @param array{path: string, message: string, code: string} $violation */
    public function addViolation(array $violation): void
    {
        $this->violations[] = $violation;
    }

    public function count(): int
    {
        return \count($this->violations);
    }

    public function throwIfAny(): void
    {
        if ([] !== $this->violations) {
            throw new ValidationFailed($this->violations);
        }
    }
}
