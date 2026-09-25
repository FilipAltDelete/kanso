<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Managing API keys over REST: /api/api-keys. */
final class ApiKeyApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnAdminCreatesAKeyThatWorksAndIsShownOnlyOnce(): void
    {
        $email = $this->signInAs(Role::ADMIN);
        $name = 'Shopify '.bin2hex(random_bytes(4));

        $created = $this->api('POST', '/api/api-keys', ['name' => $name, 'role' => Role::OPERATOR, 'expiresAt' => '2099-01-01T00:00:00Z']);

        self::assertSame(201, $this->responseStatus());
        self::assertIsString($created['key']);
        self::assertStringStartsWith('kso_', $created['key']);
        self::assertSame($name, $created['name']);
        self::assertSame(Role::OPERATOR, $created['role']);
        self::assertSame('active', $created['status']);
        self::assertSame($email, $created['createdByName']);
        self::assertSame('2099-01-01T00:00:00+00:00', $created['expiresAt']);
        self::assertNull($created['lastUsedAt']);

        $shown = $this->api('GET', '/api/api-keys/'.$created['id']);
        self::assertSame(200, $this->responseStatus());
        self::assertArrayNotHasKey('key', $shown);

        $list = $this->api('GET', '/api/api-keys?q='.urlencode($name));
        self::assertSame(1, $list['totalItems']);
        self::assertArrayNotHasKey('key', $list['member'][0]);
        self::assertNotContains($created['key'], array_filter($list['member'][0], is_string(...)));

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $created['key']]);
        self::assertResponseIsSuccessful();
        self::assertSame($name, $this->json()['name']);
    }

    public function testARevokedKeyStopsWorkingAndStaysListed(): void
    {
        $this->signInAs(Role::ADMIN);
        $created = $this->api('POST', '/api/api-keys', ['name' => 'Old ERP', 'role' => Role::VIEWER]);

        // As the web UI sends it: no body, so no Content-Type either.
        $this->client->request('POST', '/api/api-keys/'.$created['id'].'/revoke', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_ACCEPT' => 'application/ld+json']);
        $revoked = $this->json();

        self::assertSame(200, $this->responseStatus());
        self::assertSame('revoked', $revoked['status']);
        self::assertNotNull($revoked['revokedAt']);
        self::assertArrayNotHasKey('key', $revoked);

        $again = $this->api('POST', '/api/api-keys/'.$created['id'].'/revoke');
        self::assertSame(200, $this->responseStatus());
        self::assertSame($revoked['revokedAt'], $again['revokedAt'], 'revoking twice changes nothing');

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $created['key']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokingAnUnknownKeyIsA404(): void
    {
        $this->signInAs(Role::ADMIN);

        $this->api('POST', '/api/api-keys/0192f000-0000-7000-8000-000000000000/revoke');
        self::assertSame(404, $this->responseStatus());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $this->api('GET', '/api/api-keys/not-a-uuid');
        self::assertSame(404, $this->responseStatus());
    }

    public function testInvalidInputListsEveryProblem(): void
    {
        $this->signInAs(Role::ADMIN);

        $problem = $this->api('POST', '/api/api-keys', ['name' => ' ', 'role' => Role::ADMIN, 'expiresAt' => '2001-01-01T00:00:00Z']);

        self::assertSame(422, $this->responseStatus());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(
            [['name', 'required'], ['role', 'forbidden_role'], ['expiresAt', 'in_past']],
            array_map(static fn (array $v): array => [$v['path'], $v['code']], $problem['violations']),
        );

        $problem = $this->api('POST', '/api/api-keys', ['name' => ['x'], 'role' => 'ROLE_VIEWER', 'expiresAt' => 'next week']);
        self::assertSame(422, $this->responseStatus());
        self::assertSame(['name', 'expiresAt'], array_column($problem['violations'], 'path'));
    }

    public function testOnlyAdminsManageKeys(): void
    {
        $this->signInAs(Role::OPERATOR);

        $this->api('GET', '/api/api-keys');
        self::assertSame(403, $this->responseStatus());
        $this->api('POST', '/api/api-keys', ['name' => 'Sneaky', 'role' => Role::OPERATOR]);
        self::assertSame(403, $this->responseStatus());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testAKeyCannotManageKeys(): void
    {
        $this->signInAs(Role::ADMIN);
        $created = $this->api('POST', '/api/api-keys', ['name' => 'Integration', 'role' => Role::OPERATOR]);

        $this->client->request('GET', '/api/api-keys', server: ['HTTP_X_API_KEY' => $created['key']]);

        self::assertResponseStatusCodeSame(403);
    }
}
