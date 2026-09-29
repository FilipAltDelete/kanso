<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface as SymfonyHasher;
use Symfony\Contracts\Cache\CacheInterface;

/** The algorithm is whatever security.yaml configures for AuthenticatedUser. */
final class SymfonyPasswordHasher implements PasswordHasherInterface
{
    private const string DECOY_KEY = 'kanso.password_decoy';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $factory,
        private readonly CacheInterface $cache,
    ) {
    }

    public function hash(string $plainPassword): string
    {
        return $this->hasher()->hash($plainPassword);
    }

    public function verify(string $hash, string $plainPassword): bool
    {
        return $this->hasher()->verify($hash, $plainPassword);
    }

    /**
     * Made once and cached: making it costs as much as a verification, so
     * making it per request would make an unknown email the slow one. A
     * cached decoy made with other settings (the algorithm's cost was
     * raised) is made again.
     */
    public function decoyHash(): string
    {
        $hasher = $this->hasher();
        $make = static fn (): string => $hasher->hash(bin2hex(random_bytes(32)));

        $decoy = $this->cache->get(self::DECOY_KEY, $make);
        if (!$hasher->needsRehash($decoy)) {
            return $decoy;
        }

        $this->cache->delete(self::DECOY_KEY);

        return $this->cache->get(self::DECOY_KEY, $make);
    }

    private function hasher(): SymfonyHasher
    {
        return $this->factory->getPasswordHasher(AuthenticatedUser::class);
    }
}
