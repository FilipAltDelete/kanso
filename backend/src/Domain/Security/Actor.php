<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

/** Who made a change, as an audit trail records it. */
final readonly class Actor
{
    public function __construct(
        public ?string $id,
        public string $label,
    ) {
    }

    public static function console(): self
    {
        return new self(null, 'console');
    }
}
