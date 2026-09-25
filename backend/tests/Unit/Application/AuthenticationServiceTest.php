<?php

declare(strict_types=1);

namespace Kanso\Tests\Unit\Application;

use Kanso\Application\Exception\AuthenticationFailed;
use Kanso\Application\Exception\TooManyAttempts;
use Kanso\Application\Security\AuthenticationService;
use Kanso\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Domain\Security\PasswordHasherInterface;
use Kanso\Domain\Security\RefreshTokenStoreInterface;
use Kanso\Domain\User\Role;
use Kanso\Domain\User\User;
use Kanso\Domain\User\UserStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AuthenticationServiceTest extends TestCase
{
    private UserStoreInterface $users;
    private RefreshTokenStoreInterface $refreshTokens;
    private AuthenticationService $service;

    protected function setUp(): void
    {
        $this->users = new class implements UserStoreInterface {
            /** @var array<string, User> */
            private array $byId = [];

            public function findById(string $id): ?User
            {
                return $this->byId[$id] ?? null;
            }

            public function findByEmail(string $email): ?User
            {
                foreach ($this->byId as $user) {
                    if ($user->email() === $email) {
                        return $user;
                    }
                }

                return null;
            }

            public function save(User $user): void
            {
                $this->byId[(string) $user->id()] = $user;
            }

            public function count(): int
            {
                return \count($this->byId);
            }
        };

        $this->refreshTokens = new class implements RefreshTokenStoreInterface {
            /** @var array<string, string> */
            private array $tokens = [];

            public function issue(string $userId): string
            {
                $token = bin2hex(random_bytes(8));
                $this->tokens[$token] = $userId;

                return $token;
            }

            public function consume(string $token): ?string
            {
                $userId = $this->tokens[$token] ?? null;
                unset($this->tokens[$token]);

                return $userId;
            }

            public function revoke(string $token): void
            {
                unset($this->tokens[$token]);
            }

            public function revokeAllFor(string $userId): void
            {
                $this->tokens = array_filter($this->tokens, static fn (string $id): bool => $id !== $userId);
            }

            public function ttl(): int
            {
                return 3600;
            }
        };

        $hasher = new class implements PasswordHasherInterface {
            public function hash(string $plainPassword): string
            {
                return 'hashed:'.$plainPassword;
            }

            public function verify(string $hash, string $plainPassword): bool
            {
                return $hash === 'hashed:'.$plainPassword;
            }
        };

        $accessTokens = new class implements AccessTokenIssuerInterface {
            public function issue(User $user): string
            {
                return 'access:'.$user->id();
            }

            public function ttl(): int
            {
                return 900;
            }
        };

        $limiter = new RateLimiterFactory(
            ['id' => 'login', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        $this->service = new AuthenticationService($this->users, $hasher, $accessTokens, $this->refreshTokens, $limiter);
    }

    public function testLoginIssuesBothTokens(): void
    {
        $user = $this->user('ops@example.com', 'secret');

        $tokens = $this->service->login('ops@example.com', 'secret', '127.0.0.1');

        self::assertSame('access:'.$user->id(), $tokens->accessToken);
        self::assertSame(900, $tokens->expiresIn);
        self::assertNotSame('', $tokens->refreshToken);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->user('ops@example.com', 'secret');

        $this->expectException(AuthenticationFailed::class);
        $this->service->login('ops@example.com', 'wrong', '127.0.0.1');
    }

    public function testDisabledUserCannotSignIn(): void
    {
        $this->user('ops@example.com', 'secret')->disable();

        $this->expectException(AuthenticationFailed::class);
        $this->service->login('ops@example.com', 'secret', '127.0.0.1');
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        $this->user('ops@example.com', 'secret');

        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->service->login('ops@example.com', 'wrong', '127.0.0.1');
            } catch (AuthenticationFailed) {
            }
        }

        $this->expectException(TooManyAttempts::class);
        $this->service->login('ops@example.com', 'secret', '127.0.0.1');
    }

    public function testRefreshTokenIsSpentOnUse(): void
    {
        $this->user('ops@example.com', 'secret');
        $first = $this->service->login('ops@example.com', 'secret', '127.0.0.1');

        $second = $this->service->refresh($first->refreshToken);
        self::assertNotSame($first->refreshToken, $second->refreshToken);

        $this->expectException(AuthenticationFailed::class);
        $this->service->refresh($first->refreshToken);
    }

    private function user(string $email, string $password): User
    {
        $user = new User($email, [Role::OPERATOR], null, new \DateTimeImmutable());
        $user->setPasswordHash('hashed:'.$password);
        $this->users->save($user);

        return $user;
    }
}
