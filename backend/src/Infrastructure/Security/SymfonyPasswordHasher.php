<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface as SymfonyHasher;

/** The algorithm is whatever security.yaml configures for AuthenticatedUser. */
final class SymfonyPasswordHasher implements PasswordHasherInterface
{
    public function __construct(private readonly PasswordHasherFactoryInterface $factory)
    {
    }

    public function hash(string $plainPassword): string
    {
        return $this->hasher()->hash($plainPassword);
    }

    public function verify(string $hash, string $plainPassword): bool
    {
        return $this->hasher()->verify($hash, $plainPassword);
    }

    private function hasher(): SymfonyHasher
    {
        return $this->factory->getPasswordHasher(AuthenticatedUser::class);
    }
}
