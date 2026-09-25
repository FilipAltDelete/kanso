<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\User;

/** The Phase 0 role model; the hierarchy is in config/packages/security.yaml. */
final class Role
{
    public const string ADMIN = 'ROLE_ADMIN';
    public const string OPERATOR = 'ROLE_OPERATOR';
    public const string VIEWER = 'ROLE_VIEWER';

    public const array ALL = [self::ADMIN, self::OPERATOR, self::VIEWER];

    /**
     * The one role that covers the rest of a list, since each role includes
     * the ones below it. A user created from the console may have several.
     *
     * @param list<string> $roles
     */
    public static function highest(array $roles): string
    {
        foreach (self::ALL as $role) {
            if (\in_array($role, $roles, true)) {
                return $role;
            }
        }

        return self::VIEWER;
    }

    private function __construct()
    {
    }
}
