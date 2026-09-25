<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Application\Security\ApiKeyService;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiKeyTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAKeyInTheApiKeyHeaderAuthenticates(): void
    {
        ['key' => $key, 'plainKey' => $plainKey] = $this->service()->create('Shopify sync', Role::OPERATOR);

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $plainKey]);

        self::assertResponseIsSuccessful();
        $me = $this->json();
        self::assertSame($key->identifier(), $me['id']);
        self::assertSame('Shopify sync', $me['name']);
        self::assertNull($me['email']);
        self::assertContains(Role::OPERATOR, $me['roles']);
        self::assertNotContains(Role::ADMIN, $me['roles']);
    }

    public function testAKeyAsABearerTokenAuthenticates(): void
    {
        ['plainKey' => $plainKey] = $this->service()->create('ERP', Role::VIEWER);

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$plainKey]);

        self::assertResponseIsSuccessful();
        self::assertSame([Role::VIEWER], $this->json()['roles']);
    }

    public function testOnlyTheHashIsStoredAndUseIsRecorded(): void
    {
        ['key' => $key, 'plainKey' => $plainKey] = $this->service()->create('ERP', Role::VIEWER);

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $plainKey]);
        self::assertResponseIsSuccessful();

        $row = $this->connection()->fetchAssociative('SELECT * FROM api_key WHERE id = ?', [$key->id()->toBinary()]);
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $plainKey), $row['key_hash']);
        self::assertNotContains($plainKey, array_map(strval(...), array_filter($row, is_string(...))));
        self::assertNotNull($row['last_used_at']);
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => ApiKey::PREFIX.'not-a-real-key']);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('This API key is not valid.', $this->json()['detail']);
    }

    public function testARevokedKeyIsRejected(): void
    {
        ['key' => $key, 'plainKey' => $plainKey] = $this->service()->create('Old sync', Role::OPERATOR);
        $this->service()->revoke((string) $key->id());

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $plainKey]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnExpiredKeyIsRejected(): void
    {
        ['key' => $key, 'plainKey' => $plainKey] = $this->service()->create('Short-lived', Role::OPERATOR, new \DateTimeImmutable('+1 hour'));
        $this->connection()->executeStatement(
            'UPDATE api_key SET expires_at = ? WHERE id = ?',
            [new \DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s'), $key->id()->toBinary()],
        );
        // The key created above is still in the identity map with its old expiry.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $plainKey]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('This API key has expired.', $this->json()['detail']);
    }

    public function testEachKeyHasItsOwnRateLimit(): void
    {
        ['plainKey' => $busy] = $this->service()->create('Busy', Role::VIEWER);
        ['plainKey' => $quiet] = $this->service()->create('Quiet', Role::VIEWER);

        // The test environment allows five requests a minute per key.
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $busy]);
            self::assertResponseIsSuccessful();
        }

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $busy]);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $quiet]);
        self::assertResponseIsSuccessful();
    }

    private function service(): ApiKeyService
    {
        $service = static::getContainer()->get(ApiKeyService::class);
        self::assertInstanceOf(ApiKeyService::class, $service);

        return $service;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
