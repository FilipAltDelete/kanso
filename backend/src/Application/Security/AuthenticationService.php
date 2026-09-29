<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Security;

use Kanso\Core\Internal\Application\Exception\AuthenticationFailed;
use Kanso\Core\Internal\Application\Exception\TooManyAttempts;
use Kanso\Core\Internal\Application\Order\OrderInput;
use Kanso\Core\Internal\Application\User\PasswordPolicy;
use Kanso\Core\Internal\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Core\Internal\Domain\Security\IssuedTokens;
use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Login, refresh and logout. The access token is short-lived and held in the
 * browser's memory; the refresh token is rotated on every use. Each sign-in
 * starts a family of refresh tokens, and a spent token presented again ends
 * its family: someone holds a copy (ADR-0019).
 */
final class AuthenticationService
{
    /** The key of the one window every failed sign-in counts against. */
    public const string EVERYONE = 'installation';

    private const string SIGN_IN_THROTTLED = 'Too many sign-in attempts. Wait a few minutes and try again.';
    private const string SESSION_OVER = 'The session has expired. Sign in again.';

    public function __construct(
        private readonly UserStoreInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly AccessTokenIssuerInterface $accessTokens,
        private readonly RefreshTokenStoreInterface $refreshTokens,
        private readonly RateLimiterFactoryInterface $loginLimiter,
        private readonly RateLimiterFactoryInterface $loginAddressLimiter,
        private readonly RateLimiterFactoryInterface $loginTotalLimiter,
        private readonly RateLimiterFactoryInterface $refreshLimiter,
        private readonly SecurityLog $log,
    ) {
    }

    /**
     * Three limits. Attempts per email and address, forgiven on success: one
     * account guessed at from one place. Failures per address, and failures
     * in total: one password tried on many accounts, from one place or from
     * many. Only failures count towards the last two, so a warehouse signing
     * in from behind one address is never held up by its own successes.
     */
    public function login(string $email, string $password, string $clientIp): IssuedTokens
    {
        $fromAddress = $this->loginAddressLimiter->create($clientIp);
        $everyone = $this->loginTotalLimiter->create(self::EVERYONE);
        $this->refuseIfSpent($fromAddress, 'login_address', $clientIp, self::SIGN_IN_THROTTLED);
        $this->refuseIfSpent($everyone, 'login_total', $clientIp, self::SIGN_IN_THROTTLED);

        $account = $this->loginLimiter->create(mb_strtolower($email).'|'.$clientIp);
        $attempt = $account->consume();
        if (!$attempt->isAccepted()) {
            $this->log->throttled('login', $clientIp);

            throw new TooManyAttempts(self::SIGN_IN_THROTTLED, self::retryAfter($attempt));
        }

        // One verification whatever the email: against the user's hash, or
        // against a decoy of the same cost, so the time taken does not tell
        // which accounts exist. The decoy is fetched every time for the same
        // reason.
        $decoy = $this->hasher->decoyHash();
        $user = $this->users->findByEmail($email);
        $hash = $user?->passwordHash();
        $verified = $this->hasher->verify($hash ?? $decoy, $password) && null !== $hash;

        if (null === $user || !$verified || !$user->isEnabled()) {
            $fromAddress->consume();
            $everyone->consume();
            $this->log->signInFailed(
                null === $user ? null : (string) $user->id(),
                $clientIp,
                match (true) {
                    null === $user => 'unknown_account',
                    !$verified => 'wrong_password',
                    default => 'deactivated',
                },
            );

            throw new AuthenticationFailed('Wrong email or password.');
        }

        $account->reset();
        $this->log->signedIn((string) $user->id(), $clientIp);

        return $this->issue($user, $this->refreshTokens->issue((string) $user->id()));
    }

    /**
     * Failed refreshes are limited per address. A request without a token
     * is not one: it is someone not signed in, as on every load of the
     * sign-in page.
     */
    public function refresh(?string $refreshToken, string $clientIp): IssuedTokens
    {
        if (null === $refreshToken || '' === $refreshToken) {
            throw new AuthenticationFailed(self::SESSION_OVER);
        }

        $failures = $this->refreshLimiter->create($clientIp);
        $this->refuseIfSpent($failures, 'refresh', $clientIp, 'Too many attempts to resume a session. Wait a few minutes and try again.');

        $consumed = $this->refreshTokens->consume($refreshToken);
        if (null === $consumed) {
            $failures->consume();

            throw new AuthenticationFailed(self::SESSION_OVER);
        }

        if ($consumed->replayed) {
            // Two holders of one token: the user and whoever copied it. Which
            // is which cannot be told, so the session ends for both.
            $this->refreshTokens->revokeFamily($consumed->family);
            $failures->consume();
            $this->log->refreshTokenReused($consumed->userId, $clientIp);

            throw new AuthenticationFailed(self::SESSION_OVER);
        }

        $user = $this->users->findById($consumed->userId);
        // Null as well when the family was revoked since the token was spent.
        $next = null !== $user && $user->isEnabled() ? $this->refreshTokens->rotate($consumed) : null;
        if (null === $user || null === $next) {
            throw new AuthenticationFailed(self::SESSION_OVER);
        }

        return $this->issue($user, $next);
    }

    /**
     * The signed-in user changes their own password. Every session ends,
     * this one included; the new tokens returned carry this one on, so a
     * session someone else holds cannot outlive the change.
     */
    public function changePassword(string $userId, mixed $currentPassword, mixed $newPassword): IssuedTokens
    {
        $user = $this->users->findById($userId);
        if (null === $user || !$user->isEnabled()) {
            throw new AuthenticationFailed(self::SESSION_OVER);
        }

        // A stolen access token must not become a way to guess the password.
        $limiter = $this->loginLimiter->create('password-change|'.$userId);
        $attempt = $limiter->consume();
        if (!$attempt->isAccepted()) {
            throw new TooManyAttempts('Too many attempts. Wait a few minutes and try again.', self::retryAfter($attempt));
        }

        $check = new OrderInput();
        $hash = $user->passwordHash();
        if (!\is_string($currentPassword) || '' === $currentPassword) {
            $check->violate('currentPassword', 'Enter your current password.', 'required');
        } elseif (null === $hash || !$this->hasher->verify($hash, $currentPassword)) {
            $check->violate('currentPassword', 'This is not your current password.', 'wrong_password');
        }
        $newPassword = PasswordPolicy::check($check, $newPassword, 'newPassword');
        $check->throwIfInvalid();
        \assert(null !== $newPassword);

        $limiter->reset();
        $user->setPasswordHash($this->hasher->hash($newPassword));
        $this->users->save($user);
        $this->refreshTokens->revokeAllFor($userId);
        $this->log->passwordChanged($userId, $userId);

        return $this->issue($user, $this->refreshTokens->issue($userId));
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

    private function issue(User $user, string $refreshToken): IssuedTokens
    {
        return new IssuedTokens(
            $this->accessTokens->issue($user),
            $this->accessTokens->ttl(),
            $refreshToken,
            $this->refreshTokens->ttl(),
        );
    }

    /**
     * For the limits that count failures: they are charged after the fact,
     * so this only looks at the window, without adding to it.
     */
    private function refuseIfSpent(LimiterInterface $limiter, string $limit, string $clientIp, string $detail): void
    {
        $window = $limiter->consume(0);
        if ($window->getRemainingTokens() > 0) {
            return;
        }

        $this->log->throttled($limit, $clientIp);

        throw new TooManyAttempts($detail, self::retryAfter($window));
    }

    /** Seconds until the window lets another attempt through. */
    private static function retryAfter(RateLimit $limit): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
