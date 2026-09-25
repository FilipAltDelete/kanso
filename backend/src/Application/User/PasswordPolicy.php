<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\User;

use Kanso\Core\Internal\Application\Order\OrderInput;

/**
 * What a password set in the browser must be: at least 8 characters, as
 * NIST SP 800-63B asks, and not so long that hashing it is a denial of
 * service. The console is not held to it, so the development admin can
 * stay admin/admin.
 */
final class PasswordPolicy
{
    public const int MIN = 8;
    public const int MAX = 1024;

    private function __construct()
    {
    }

    /** The password as sent, or null with a violation at `$path`. Spaces count: they are not trimmed. */
    public static function check(OrderInput $check, mixed $value, string $path): ?string
    {
        if (null === $value || '' === $value) {
            $check->violate($path, 'A password is required.', 'required');

            return null;
        }
        if (!\is_string($value)) {
            $check->violate($path, 'This value must be text.', 'type');

            return null;
        }
        if (mb_strlen($value) < self::MIN) {
            $check->violate($path, \sprintf('A password needs at least %d characters.', self::MIN), 'too_short');

            return null;
        }
        if (mb_strlen($value) > self::MAX) {
            $check->violate($path, \sprintf('This value is longer than %d characters.', self::MAX), 'too_long');

            return null;
        }

        return $value;
    }
}
