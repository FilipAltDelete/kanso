<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Api\Controller\AuthController;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    private function createUser(string $password): string
    {
        // Unique per test: the login rate limiter is keyed by email.
        $email = 'ops-'.bin2hex(random_bytes(4)).'@example.com';
        $users = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $users->create($email, $password, [Role::OPERATOR]);

        return $email;
    }

    private function login(string $email, string $password): ?string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR));

        return $this->json()['accessToken'] ?? null;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
