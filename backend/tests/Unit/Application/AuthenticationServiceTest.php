<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Application;

use Kanso\Core\Internal\Application\Exception\AuthenticationFailed;
use Kanso\Core\Internal\Application\Exception\TooManyAttempts;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Security\AuthenticationService;
use Kanso\Core\Internal\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Tests\Support\InMemoryRefreshTokens;
use Kanso\Core\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AuthenticationServiceTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryRefreshTokens $refreshTokens;
    private AuthenticationService $service;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->refreshTokens = new InMemoryRefreshTokens();

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

    public function testChangingThePasswordEndsEveryOtherSession(): void
    {
        $user = $this->user('ops@example.com', 'secret');
        $here = $this->service->login('ops@example.com', 'secret', '127.0.0.1');
        $elsewhere = $this->service->login('ops@example.com', 'secret', '10.0.0.1');

        $tokens = $this->service->changePassword((string) $user->id(), 'secret', 'a longer one');

        self::assertSame('hashed:a longer one', $user->passwordHash());
        self::assertSame([$tokens->refreshToken], array_keys($this->refreshTokens->tokens), 'only the new session is left');
        self::assertNotSame($here->refreshToken, $tokens->refreshToken);
        self::assertNotSame($elsewhere->refreshToken, $tokens->refreshToken);
    }

    public function testChangingThePasswordTakesTheCurrentOneAndAGoodNewOne(): void
    {
        $user = $this->user('ops@example.com', 'secret');

        try {
            $this->service->changePassword((string) $user->id(), 'wrong', 'short');
            self::fail('Expected a validation failure.');
        } catch (ValidationFailed $e) {
            self::assertSame(
                [['currentPassword', 'wrong_password'], ['newPassword', 'too_short']],
                array_map(static fn (array $v): array => [$v['path'], $v['code']], $e->violations()),
            );
        }

        try {
            $this->service->changePassword((string) $user->id(), null, 42);
            self::fail('Expected a validation failure.');
        } catch (ValidationFailed $e) {
            self::assertSame(
                [['currentPassword', 'required'], ['newPassword', 'type']],
                array_map(static fn (array $v): array => [$v['path'], $v['code']], $e->violations()),
            );
        }

        self::assertSame('hashed:secret', $user->passwordHash());
    }

    public function testGuessingTheCurrentPasswordIsThrottled(): void
    {
        $user = $this->user('ops@example.com', 'secret');

        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->service->changePassword((string) $user->id(), 'guess'.$i, 'a longer one');
            } catch (ValidationFailed) {
            }
        }

        $this->expectException(TooManyAttempts::class);
        $this->service->changePassword((string) $user->id(), 'secret', 'a longer one');
    }

    public function testADeactivatedUserCannotChangeTheirPassword(): void
    {
        $user = $this->user('ops@example.com', 'secret');
        $user->disable();

        $this->expectException(AuthenticationFailed::class);
        $this->service->changePassword((string) $user->id(), 'secret', 'a longer one');
    }

    private function user(string $email, string $password): User
    {
        $user = new User($email, [Role::OPERATOR], null, new \DateTimeImmutable());
        $user->setPasswordHash('hashed:'.$password);
        $this->users->save($user);

        return $user;
    }
}
