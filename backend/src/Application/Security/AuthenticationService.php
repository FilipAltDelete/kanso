<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Security;

use Kanso\Core\Internal\Application\Exception\AuthenticationFailed;
use Kanso\Core\Internal\Application\Exception\TooManyAttempts;
use Kanso\Core\Internal\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Core\Internal\Domain\Security\IssuedTokens;
use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Login, refresh and logout. The access token is short-lived and held in the
 * browser's memory; the refresh token is rotated on every use, so a replayed
 * one fails.
 */
final class AuthenticationService
{
    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly AccessTokenIssuerInterface $accessTokens,
        private readonly RefreshTokenStoreInterface $refreshTokens,
        private readonly RateLimiterFactoryInterface $loginLimiter,
    ) {
    }

    public function login(string $email, string $password, string $clientIp): IssuedTokens
    {
        $limiter = $this->loginLimiter->create(mb_strtolower($email).'|'.$clientIp);
        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyAttempts('Too many sign-in attempts. Wait a few minutes and try again.');
        }

        $user = $this->users->findByEmail($email);
        $hash = $user?->passwordHash();

        if (null === $user || null === $hash || !$user->isEnabled() || !$this->hasher->verify($hash, $password)) {
            throw new AuthenticationFailed('Wrong email or password.');
        }

        $limiter->reset();

        return $this->issue($user);
    }

    public function refresh(?string $refreshToken): IssuedTokens
    {
        $userId = $this->refreshTokens->consume((string) $refreshToken);
        $user = null === $userId ? null : $this->users->findById($userId);

        if (null === $user || !$user->isEnabled()) {
            throw new AuthenticationFailed('The session has expired. Sign in again.');
        }

        return $this->issue($user);
    }

    public function logout(?string $refreshToken): void
    {
        $this->refreshTokens->revoke((string) $refreshToken);
    }

    /** @return array{email: string, name: ?string} */
    public function describe(string $userId): array
    {
        $user = $this->users->findById($userId);
        if (null === $user) {
            throw new AuthenticationFailed('The user no longer exists.');
        }

        return ['email' => $user->email(), 'name' => $user->name()];
    }

    private function issue(User $user): IssuedTokens
    {
        return new IssuedTokens(
            $this->accessTokens->issue($user),
            $this->accessTokens->ttl(),
            $this->refreshTokens->issue((string) $user->id()),
            $this->refreshTokens->ttl(),
        );
    }
}
