<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Api\Controller\AuthController;
use Kanso\Core\Internal\Application\Security\AuthenticationService;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\User\Role;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AuthTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testSignInAndReadTheCurrentUser(): void
    {
        $email = $this->createUser('secret');

        $token = $this->login($email, 'secret');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->client->getCookieJar()->get(AuthController::REFRESH_COOKIE, '/api/auth'));

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        $me = $this->json();
        self::assertSame($email, $me['email']);
        self::assertContains(Role::OPERATOR, $me['roles']);
    }

    public function testWrongPasswordIsAProblemResponse(): void
    {
        $email = $this->createUser('secret');

        $this->login($email, 'wrong');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('Wrong email or password.', $this->json()['detail']);
    }

    public function testApiNeedsAToken(): void
    {
        $this->client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshRotatesTheCookie(): void
    {
        $email = $this->createUser('secret');
        $this->login($email, 'secret');
        $first = $this->client->getCookieJar()->get(AuthController::REFRESH_COOKIE, '/api/auth')?->getValue();

        $this->client->request('POST', '/api/auth/refresh');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('accessToken', $this->json());

        $second = $this->client->getCookieJar()->get(AuthController::REFRESH_COOKIE, '/api/auth')?->getValue();
        self::assertNotSame($first, $second);
    }

    /**
     * The flags as the browser receives them: out of reach of scripts, never
     * sent from another site, only to the auth endpoints, and over HTTPS only
     * when the request came over HTTPS (behind a proxy, only when it is
     * trusted to say so: TRUSTED_PROXIES).
     */
    public function testTheRefreshCookieCarriesItsFlags(): void
    {
        $email = $this->createUser('secret');

        $this->login($email, 'secret', https: true);
        $cookie = $this->refreshCookieHeader();
        foreach (['path=/api/auth', 'httponly', 'samesite=strict', 'secure'] as $flag) {
            self::assertContains($flag, $cookie, 'on sign-in');
        }
        self::assertLivesThirtyDays($cookie);

        $this->client->request('POST', 'https://localhost/api/auth/refresh');
        self::assertResponseIsSuccessful();
        $rotated = $this->refreshCookieHeader();
        foreach (['path=/api/auth', 'httponly', 'samesite=strict', 'secure'] as $flag) {
            self::assertContains($flag, $rotated, 'on refresh');
        }
        self::assertLivesThirtyDays($rotated);

        $this->client->request('POST', 'https://localhost/api/auth/logout');
        self::assertResponseStatusCodeSame(204);
        $cleared = $this->refreshCookieHeader();
        foreach (['path=/api/auth', 'httponly', 'samesite=strict', 'secure', 'max-age=0'] as $flag) {
            self::assertContains($flag, $cleared, 'on sign-out');
        }

        $this->client->getCookieJar()->clear();
        $this->login($email, 'secret');
        self::assertNotContains('secure', $this->refreshCookieHeader(), 'plain HTTP, as in development');
    }

    /** One password tried on many accounts from one address: the per-email limit never sees it. */
    public function testFailedSignInsAreLimitedPerAddress(): void
    {
        $email = $this->createUser('secret');
        $address = self::address();
        $limit = $this->limit('limiter.login_address');

        for ($i = 0; $i < $limit; ++$i) {
            $this->login('nobody-'.$i.'-'.bin2hex(random_bytes(3)).'@example.com', 'Summer2026!', $address);
            self::assertResponseStatusCodeSame(401);
        }

        $this->login($email, 'secret', $address);
        $this->assertTooManyRequests();
        self::assertSame(['throttled', 'login_address'], $this->lastLogged('limit'));

        $this->login($email, 'secret', self::address());
        self::assertResponseIsSuccessful();
    }

    public function testFailedSignInsAreLimitedInTotal(): void
    {
        $email = $this->createUser('secret');
        $total = $this->limiterFactory('limiter.login_total')->create(AuthenticationService::EVERYONE);
        $total->reset();

        try {
            // As if failures from many addresses had all but spent the window.
            $total->consume($this->limit('limiter.login_total') - 1);
            $this->login($email, 'wrong', self::address());
            self::assertResponseStatusCodeSame(401);

            $this->login($email, 'secret', self::address());
            $this->assertTooManyRequests();
            self::assertSame(['throttled', 'login_total'], $this->lastLogged('limit'));
        } finally {
            $total->reset();
        }
    }

    public function testRepeatedSignInsToOneAccountAreLimited(): void
    {
        $email = $this->createUser('secret');
        $address = self::address();

        for ($i = 0; $i < $this->limit('limiter.login'); ++$i) {
            $this->login($email, 'wrong', $address);
            self::assertResponseStatusCodeSame(401);
        }

        $this->login($email, 'secret', $address);
        $this->assertTooManyRequests();
    }

    public function testFailedRefreshesAreLimitedPerAddress(): void
    {
        $email = $this->createUser('secret');
        $address = self::address();
        $this->login($email, 'secret', $address);
        $session = $this->client->getCookieJar()->get(AuthController::REFRESH_COOKIE, '/api/auth')?->getValue();
        self::assertIsString($session);
        $this->client->getCookieJar()->clear();

        for ($i = 0; $i < $this->limit('limiter.refresh'); ++$i) {
            $this->refresh($address, bin2hex(random_bytes(16)));
            self::assertResponseStatusCodeSame(401);
        }

        $this->refresh($address, $session);
        $this->assertTooManyRequests();
        self::assertSame(['throttled', 'refresh'], $this->lastLogged('limit'));

        $this->refresh(self::address(), $session);
        self::assertResponseIsSuccessful('the refused refresh did not spend the token');
    }

    public function testASpentRefreshTokenPresentedAgainSignsTheSessionOut(): void
    {
        $email = $this->createUser('secret');
        $this->login($email, 'secret');
        $stolen = $this->refreshCookie();
        $this->login($email, 'secret');
        $otherSession = $this->refreshCookie();

        $this->refresh('127.0.0.1', $stolen);
        self::assertResponseIsSuccessful();
        $current = $this->refreshCookie();

        $this->refresh('192.0.2.66', $stolen);
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $logged = $this->lastLogged('user_id', 'client_ip');
        self::assertSame(['refresh_token_reused', '192.0.2.66'], [$logged[0], $logged[2]]);
        self::assertIsString($logged[1]);

        $this->refresh('127.0.0.1', $current);
        self::assertResponseStatusCodeSame(401, 'the whole family is revoked, not only the replayed token');

        $this->refresh('127.0.0.1', $otherSession);
        self::assertResponseIsSuccessful('another sign-in is another family');
    }

    public function testSignInsAreLoggedWithoutTheEmailOrPassword(): void
    {
        $email = $this->createUser('Correct-Horse-9');

        $this->login($email, 'Guess-1234');
        self::assertSame(['login_failed', 'wrong_password', '127.0.0.1'], $this->lastLogged('reason', 'client_ip'));
        $failed = $this->securityLog();

        $this->login($email, 'Correct-Horse-9');
        self::assertSame(['login_succeeded', '127.0.0.1'], $this->lastLogged('client_ip'));
        $succeeded = $this->securityLog();

        foreach ([$email, 'Guess-1234', 'Correct-Horse-9', $this->refreshCookie()] as $secret) {
            self::assertStringNotContainsString($secret, $failed.$succeeded);
        }
    }

    private function createUser(string $password): string
    {
        // Unique per test: the login rate limiter is keyed by email.
        $email = 'ops-'.bin2hex(random_bytes(4)).'@example.com';
        $users = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $users->create($email, $password, [Role::OPERATOR]);

        return $email;
    }

    private function login(string $email, string $password, string $address = '127.0.0.1', bool $https = false): ?string
    {
        // Absolute: a relative URI would take the scheme of the request before.
        $this->client->request(
            'POST',
            ($https ? 'https' : 'http').'://localhost/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $address],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );

        return $this->json()['accessToken'] ?? null;
    }

    /**
     * A refresh with exactly this token, as a browser elsewhere would send
     * it. Through the cookie jar: the test client ignores a Cookie header.
     */
    private function refresh(string $address, string $token): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie(AuthController::REFRESH_COOKIE, $token, null, '/api/auth'));
        $this->client->request('POST', 'http://localhost/api/auth/refresh', server: ['REMOTE_ADDR' => $address]);
    }

    private function refreshCookie(): string
    {
        $value = $this->client->getCookieJar()->get(AuthController::REFRESH_COOKIE, '/api/auth')?->getValue();
        self::assertIsString($value, 'no refresh cookie');

        return $value;
    }

    /** @return list<string> the attributes of the last response's refresh cookie, lowercased */
    private function refreshCookieHeader(): array
    {
        foreach ($this->client->getResponse()->headers->all('set-cookie') as $header) {
            if (str_starts_with((string) $header, AuthController::REFRESH_COOKIE.'=')) {
                return array_map(static fn (string $part): string => strtolower(trim($part)), \array_slice(explode(';', (string) $header), 1));
            }
        }

        self::fail('The response sets no refresh cookie.');
    }

    /**
     * Max-Age is counted when the header is written, a moment after the
     * cookie is made.
     *
     * @param list<string> $cookie
     */
    private static function assertLivesThirtyDays(array $cookie): void
    {
        $maxAge = array_values(array_filter($cookie, static fn (string $part): bool => str_starts_with($part, 'max-age=')));
        self::assertCount(1, $maxAge);
        self::assertEqualsWithDelta(30 * 24 * 3600, (int) substr($maxAge[0], \strlen('max-age=')), 5);
    }

    private function assertTooManyRequests(): void
    {
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', $retryAfter);
        self::assertSame(429, $this->json()['status']);
    }

    private static function address(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }

    private function limiterFactory(string $id): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get($id);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    /** The limit as configured, so the test follows framework.yaml. */
    private function limit(string $id): int
    {
        return $this->limiterFactory($id)->create('peek')->consume(0)->getLimit();
    }

    private function securityHandler(): TestHandler
    {
        $handler = static::getContainer()->get('monolog.handler.security');
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }

    /**
     * The last line the last request wrote to the security log: its event,
     * then the given fields.
     *
     * @return list<mixed>
     */
    private function lastLogged(string ...$fields): array
    {
        $records = $this->securityHandler()->getRecords();
        self::assertNotEmpty($records, 'the request wrote nothing to the security log');
        $last = $records[\count($records) - 1];

        return [$last->context['event'], ...array_values(array_map(static fn (string $field): mixed => $last->context[$field] ?? null, $fields))];
    }

    /** Everything the last request wrote to the security log, as JSON. */
    private function securityLog(): string
    {
        return json_encode(array_map(static fn (LogRecord $record): array => $record->toArray(), $this->securityHandler()->getRecords()), \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
