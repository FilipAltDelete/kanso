<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Security;

use Psr\Log\LoggerInterface;

/**
 * The security log (ADR-0019): sign-ins, sessions, and changes to who may do
 * what. It is its own Monolog channel, `kanso_security`, with its own
 * handler, so an installation can send it somewhere else or keep it longer.
 *
 * Every line has an `event` and ids: users, API keys, and the client address.
 * Never a password, a token, a key, or the email someone typed, which is
 * sometimes a password typed into the wrong field. `actor_id` is the user
 * who made a change, or null when it was made from the console.
 */
final class SecurityLog
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function signedIn(string $userId, string $clientIp): void
    {
        $this->logger->info('Signed in.', ['event' => 'login_succeeded', 'user_id' => $userId, 'client_ip' => $clientIp]);
    }

    /** @param 'unknown_account'|'wrong_password'|'deactivated' $reason */
    public function signInFailed(?string $userId, string $clientIp, string $reason): void
    {
        $this->logger->warning('Sign-in failed.', ['event' => 'login_failed', 'user_id' => $userId, 'client_ip' => $clientIp, 'reason' => $reason]);
    }

    /** @param string $limit which limit was reached (a rate limiter's name) */
    public function throttled(string $limit, string $clientIp): void
    {
        $this->logger->warning('Refused by a rate limit.', ['event' => 'throttled', 'limit' => $limit, 'client_ip' => $clientIp]);
    }

    public function refreshTokenReused(string $userId, string $clientIp): void
    {
        $this->logger->warning('A spent refresh token was presented again; the session is ended.', ['event' => 'refresh_token_reused', 'user_id' => $userId, 'client_ip' => $clientIp]);
    }

    public function passwordChanged(string $userId, ?string $actorId): void
    {
        $this->logger->notice('Password changed.', ['event' => 'password_changed', 'user_id' => $userId, 'actor_id' => $actorId]);
    }

    public function userCreated(string $userId, string $role, ?string $actorId): void
    {
        $this->logger->notice('User created.', ['event' => 'user_created', 'user_id' => $userId, 'role' => $role, 'actor_id' => $actorId]);
    }

    public function roleChanged(string $userId, string $from, string $to, ?string $actorId): void
    {
        $this->logger->notice('Role changed.', ['event' => 'user_role_changed', 'user_id' => $userId, 'from' => $from, 'to' => $to, 'actor_id' => $actorId]);
    }

    public function userDeactivated(string $userId, ?string $actorId): void
    {
        $this->logger->notice('User deactivated.', ['event' => 'user_deactivated', 'user_id' => $userId, 'actor_id' => $actorId]);
    }

    public function userActivated(string $userId, ?string $actorId): void
    {
        $this->logger->notice('User activated.', ['event' => 'user_activated', 'user_id' => $userId, 'actor_id' => $actorId]);
    }

    public function apiKeyCreated(string $keyId, string $role, ?string $actorId): void
    {
        $this->logger->notice('API key created.', ['event' => 'api_key_created', 'api_key_id' => $keyId, 'role' => $role, 'actor_id' => $actorId]);
    }

    public function apiKeyRevoked(string $keyId, ?string $actorId): void
    {
        $this->logger->notice('API key revoked.', ['event' => 'api_key_revoked', 'api_key_id' => $keyId, 'actor_id' => $actorId]);
    }
}
