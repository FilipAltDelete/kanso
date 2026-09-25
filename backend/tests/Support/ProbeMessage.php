<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

/** A message with nothing in it, for tests of what happens around a message. */
final readonly class ProbeMessage
{
    public function __construct(public string $payload = 'probe')
    {
    }
}
