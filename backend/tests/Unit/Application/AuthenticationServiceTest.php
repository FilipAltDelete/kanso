<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Application;

use Kanso\Core\Internal\Application\Exception\AuthenticationFailed;
use Kanso\Core\Internal\Application\Exception\TooManyAttempts;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Security\AuthenticationService;
use Kanso\Core\Internal\Application\Security\SecurityLog;
use Kanso\Core\Internal\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Tests\Support\InMemoryRefreshTokens;
use Kanso\Core\Tests\Support\InMemoryUsers;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AuthenticationServiceTest extends TestCase
{
    public const string DECOY = 'hashed:decoy';

    private InMemoryUsers $users;
    private InMemoryRefreshTokens $refreshTokens;
    private TestHandler $log;
    /** @var \ArrayObject<int, string> the hash each verify() call checked against */
    private \ArrayObject $verified;
    private AuthenticationService $service;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->refreshTokens = new InMemoryRefreshTokens();
        $this->log = new TestHandler();
        $this->verified = new \ArrayObject();

        $hasher = new class($this->verified) implements PasswordHasherInterface {
            /** @param \ArrayObject<int, string> $verified */
            public function __construct(private readonly \ArrayObject $verified)
            {
            }

            public function hash(string $plainPassword): string
            {
                return 'hashed:'.$plainPassword;
            }

            public function verify(string $hash, string $plainPassword): bool
            {
                $this->verified->append($hash);

                return $hash === 'hashed:'.$plainPassword;
            }

            public function decoyHash(): string
            {
                return AuthenticationServiceTest::DECOY;
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

        $limiter = static fn (string $id, int $limit): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        $this->service = new AuthenticationService(
            $this->users,
            $hasher,
            $accessTokens,
            $this->refreshTokens,
            $limiter('login', 3),
            $limiter('login_address', 5),
            $limiter('login_total', 8),
            $limiter('refresh', 3),
            new SecurityLog(new Logger('kanso_security', [$this->log])),
        );
    }

    public function testLoginIssuesBothTokens(): void
    {
        $user = $this->user('ops@example.com', 'secret');

        $tokens = $this->service->login('ops@example.com', 'secret', '127.0.0.1');

        self::assertSame('access:'.$user->id(), $tokens->accessToken);
        self::assertSame(900, $tokens->expiresIn);
        self::assertNotSame('', $tokens->refreshToken);
        self::assertSame([['login_succeeded', (string) $user->id(), '127.0.0.1']], $this->logged('user_id', 'client_ip'));
    }

    public function testWrongPasswordIsRejected(): void
    {
        $user = $this->user('ops@example.com', 'secret');

        $this->assertLoginFails('ops@example.com', 'wrong');
        self::assertSame([['login_failed', (string) $user->id(), 'wrong_password']], $this->logged('user_id', 'reason'));
    }

    public function testDisabledUserCannotSignIn(): void
    {
        $user = $this->user('ops@example.com', 'secret');
        $user->disable();

        $this->assertLoginFails('ops@example.com', 'secret');
        self::assertSame([['login_failed', (string) $user->id(), 'deactivated']], $this->logged('user_id', 'reason'));
    }

    /**
     * The same work whatever the email: one verification each, against the
     * user's hash when there is a user, against the decoy when there is not.
     */
    public function testEveryLoginVerifiesExactlyOnePassword(): void
    {
        $this->user('known@example.com', 'secret');
        $this->user('gone@example.com', 'secret')->disable();

        $this->assertLoginFails('nobody@example.com', 'secret');
        $this->assertLoginFails('known@example.com', 'wrong');
        $this->assertLoginFails('gone@example.com', 'secret');
        $this->service->login('known@example.com', 'secret', '127.0.0.1');

        self::assertSame([self::DECOY, 'hashed:secret', 'hashed:secret', 'hashed:secret'], $this->verified->getArrayCopy());
        self::assertSame(
            [['login_failed', 'unknown_account'], ['login_failed', 'wrong_password'], ['login_failed', 'deactivated'], ['login_succeeded', null]],
            $this->logged('reason'),
        );
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        $this->user('ops@example.com', 'secret');

        for ($i = 0; $i < 3; ++$i) {
            $this->assertLoginFails('ops@example.com', 'wrong');
        }

        $e = $this->assertThrottled(fn () => $this->service->login('ops@example.com', 'secret', '127.0.0.1'));
        self::assertGreaterThanOrEqual(1, $e->retryAfter);
        self::assertSame(['Retry-After' => (string) $e->retryAfter], $e->headers());
    }

    public function testOnePasswordTriedOnManyAccountsFromOneAddressIsThrottled(): void
    {
        $this->user('ops@example.com', 'secret');

        // Five different emails: the per-email limit never sees more than one.
        for ($i = 0; $i < 5; ++$i) {
            $this->assertLoginFails('user'.$i.'@example.com', 'Summer2026!', '10.0.0.7');
        }

        $this->assertThrottled(fn () => $this->service->login('ops@example.com', 'secret', '10.0.0.7'));
        self::assertSame(['throttled', 'login_address'], $this->logged('limit')[5]);

        // Another address is not held up.
        $this->service->login('ops@example.com', 'secret', '10.0.0.8');
    }

    public function testSuccessesDoNotCountTowardsTheAddressOrTheTotal(): void
    {
        $this->user('ops@example.com', 'secret');

        for ($i = 0; $i < 12; ++$i) {
            $this->service->login('ops@example.com', 'secret', '10.0.0.7');
        }

        $this->expectNotToPerformAssertions();
    }

    public function testFailuresFromManyAddressesAreThrottledInTotal(): void
    {
        $this->user('ops@example.com', 'secret');

        for ($i = 0; $i < 8; ++$i) {
            $this->assertLoginFails('user'.$i.'@example.com', 'Summer2026!', '10.0.1.'.$i);
        }

        $this->assertThrottled(fn () => $this->service->login('ops@example.com', 'secret', '10.0.2.1'));
        self::assertSame(['throttled', 'login_total'], $this->logged('limit')[8]);
    }

    public function testRefreshTokenIsSpentOnUse(): void
    {
        $this->user('ops@example.com', 'secret');
        $first = $this->service->login('ops@example.com', 'secret', '127.0.0.1');

        $second = $this->service->refresh($first->refreshToken, '127.0.0.1');

        self::assertNotSame($first->refreshToken, $second->refreshToken);
        self::assertSame([$second->refreshToken], array_keys($this->refreshTokens->tokens));
    }

    public function testASpentTokenPresentedAgainEndsTheWholeSession(): void
    {
        $user = $this->user('ops@example.com', 'secret');
        $stolen = $this->service->login('ops@example.com', 'secret', '127.0.0.1');
        $elsewhere = $this->service->login('ops@example.com', 'secret', '10.0.0.9');
        $current = $this->service->refresh($stolen->refreshToken, '127.0.0.1');
        $this->log->clear();

        $this->assertRefreshFails($stolen->refreshToken, '192.0.2.66');

        self::assertSame([['refresh_token_reused', (string) $user->id(), '192.0.2.66']], $this->logged('user_id', 'client_ip'));
        $this->assertRefreshFails($current->refreshToken, '127.0.0.1');
        // Another sign-in is another family, and carries on.
        $this->service->refresh($elsewhere->refreshToken, '10.0.0.9');
    }

    public function testFailedRefreshesAreThrottledPerAddress(): void
    {
        $this->user('ops@example.com', 'secret');
        $session = $this->service->login('ops@example.com', 'secret', '10.0.0.7');

        for ($i = 0; $i < 3; ++$i) {
            $this->assertRefreshFails('guess-'.$i, '10.0.0.7');
        }

        $e = $this->assertThrottled(fn () => $this->service->refresh($session->refreshToken, '10.0.0.7'));
        self::assertSame(['Retry-After' => (string) $e->retryAfter], $e->headers());
        self::assertArrayHasKey($session->refreshToken, $this->refreshTokens->tokens, 'a refused refresh does not spend the token');
    }

    public function testNoTokenIsNotCountedAsAGuess(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->assertRefreshFails(null, '10.0.0.7');
        }

        $this->assertRefreshFails('guess', '10.0.0.7');
    }

    public function testSigningOutEndsTheSessionItsTokenBelongsTo(): void
    {
        $this->user('ops@example.com', 'secret');
        $first = $this->service->login('ops@example.com', 'secret', '127.0.0.1');
        $second = $this->service->refresh($first->refreshToken, '127.0.0.1');

        $this->service->logout($second->refreshToken);

        self::assertSame([], $this->refreshTokens->tokens);
    }

    public function testChangingThePasswordEndsEveryOtherSession(): void
    {
        $user = $this->user('ops@example.com', 'secret');
        $here = $this->service->login('ops@example.com', 'secret', '127.0.0.1');
        $elsewhere = $this->service->login('ops@example.com', 'secret', '10.0.0.1');
        $this->log->clear();

        $tokens = $this->service->changePassword((string) $user->id(), 'secret', 'a longer one');

        self::assertSame('hashed:a longer one', $user->passwordHash());
        self::assertSame([$tokens->refreshToken], array_keys($this->refreshTokens->tokens), 'only the new session is left');
        self::assertNotSame($here->refreshToken, $tokens->refreshToken);
        self::assertNotSame($elsewhere->refreshToken, $tokens->refreshToken);
        self::assertSame([['password_changed', (string) $user->id(), (string) $user->id()]], $this->logged('user_id', 'actor_id'));
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

        $this->assertThrottled(fn () => $this->service->changePassword((string) $user->id(), 'secret', 'a longer one'));
    }

    public function testADeactivatedUserCannotChangeTheirPassword(): void
    {
        $user = $this->user('ops@example.com', 'secret');
        $user->disable();

        $this->expectException(AuthenticationFailed::class);
        $this->service->changePassword((string) $user->id(), 'secret', 'a longer one');
    }

    public function testNothingSecretIsLogged(): void
    {
        $this->user('ops@example.com', 'Correct-Horse-9');
        $this->assertLoginFails('ops@example.com', 'Guess-1234');
        $this->assertLoginFails('Typed-Password-Here', 'Another-Guess');
        $tokens = $this->service->login('ops@example.com', 'Correct-Horse-9', '127.0.0.1');
        $next = $this->service->refresh($tokens->refreshToken, '127.0.0.1');
        $this->assertRefreshFails($tokens->refreshToken, '127.0.0.1');
        self::assertCount(4, $this->log->getRecords(), 'two failures, a sign-in and a replay; the refresh itself is not logged');

        $all = json_encode(array_map(static fn (LogRecord $record): array => $record->toArray(), $this->log->getRecords()), \JSON_THROW_ON_ERROR);
        foreach (['Correct-Horse-9', 'Guess-1234', 'Typed-Password-Here', 'Another-Guess', 'ops@example.com', $tokens->refreshToken, $tokens->accessToken, $next->refreshToken] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
    }

    private function user(string $email, string $password): User
    {
        $user = new User($email, [Role::OPERATOR], null, new \DateTimeImmutable());
        $user->setPasswordHash('hashed:'.$password);
        $this->users->save($user);

        return $user;
    }

    private function assertLoginFails(string $email, string $password, string $clientIp = '127.0.0.1'): void
    {
        try {
            $this->service->login($email, $password, $clientIp);
            self::fail('Expected the sign-in to fail.');
        } catch (AuthenticationFailed $e) {
            self::assertSame(401, $e->status());
        }
    }

    private function assertRefreshFails(?string $token, string $clientIp): void
    {
        try {
            $this->service->refresh($token, $clientIp);
            self::fail('Expected the refresh to fail.');
        } catch (AuthenticationFailed $e) {
            self::assertSame(401, $e->status());
        }
    }

    /** @param callable(): mixed $attempt */
    private function assertThrottled(callable $attempt): TooManyAttempts
    {
        try {
            $attempt();
        } catch (TooManyAttempts $e) {
            self::assertSame(429, $e->status());

            return $e;
        }

        self::fail('Expected a rate limit.');
    }

    /**
     * Each line of the security log: its event, then the given fields.
     *
     * @return list<list<mixed>>
     */
    private function logged(string ...$fields): array
    {
        return array_values(array_map(
            static fn (LogRecord $record): array => [$record->context['event'], ...array_values(array_map(static fn (string $field): mixed => $record->context[$field] ?? null, $fields))],
            $this->log->getRecords(),
        ));
    }
}
