<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Common;

/**
 * Who made a change, as the audit trail records it. The name is copied at
 * the time of the change, so the history still reads correctly after a user
 * is renamed or an API key revoked.
 */
final readonly class Actor
{
    /**
     * @param string $id   a user id, or `api-key:<id>` for an integration
     * @param string $name the user's name or email, or the key's name
     */
    public function __construct(public string $id, public string $name)
    {
    }
}
