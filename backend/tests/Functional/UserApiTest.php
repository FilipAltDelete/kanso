<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Managing users over REST (/api/users) and changing one's own password
 * (/api/auth/password). The last-admin rule is in UserServiceTest: the test
 * database always holds other admins.
 */
final class UserApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnAdminAddsSomeoneWhoCanThenSignIn(): void
    {
        $this->signInAs(Role::ADMIN);
        $email = 'picker-'.bin2hex(random_bytes(4)).'@example.com';

        $created = $this->api('POST', '/api/users', ['email' => $email, 'name' => 'Pia Picker', 'role' => Role::OPERATOR, 'password' => 'first password']);

        self::assertSame(201, $this->responseStatus());
        self::assertSame($email, $created['email']);
        self::assertSame('Pia Picker', $created['name']);
        self::assertSame(Role::OPERATOR, $created['role']);
        self::assertSame('active', $created['status']);
        self::assertArrayNotHasKey('password', $created);
        self::assertArrayNotHasKey('passwordHash', $created);

        $list = $this->api('GET', '/api/users?q='.urlencode($email));
        self::assertSame(1, $list['totalItems']);
        self::assertSame($created['id'], $list['member'][0]['id']);

        self::assertSame(200, $this->login($email, 'first password'));
    }

    public function testAnAdminChangesTheRoleAndDetails(): void
    {
        $this->signInAs(Role::ADMIN);
        $user = $this->newUser(Role::OPERATOR);

        $changed = $this->api('PATCH', '/api/users/'.$user['id'], ['role' => Role::VIEWER, 'name' => 'Viewer now']);

        self::assertSame(200, $this->responseStatus());
        self::assertSame([Role::VIEWER, 'Viewer now', $user['email']], [$changed['role'], $changed['name'], $changed['email']]);

        // The new role applies to the next request, without signing in again.
        $this->login($user['email'], 'first password');
        $token = $this->json()['accessToken'];
        $this->client->request('POST', '/api/locations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], content: '{"code":"X","name":"X"}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testADeactivatedUserIsSignedOutAtOnceAndCanBeLetBackIn(): void
    {
        $this->signInAs(Role::ADMIN);
        $user = $this->newUser(Role::OPERATOR);
        $this->login($user['email'], 'first password');
        $theirToken = $this->json()['accessToken'];
        $theirRefresh = $this->client->getCookieJar()->get('kanso_refresh', '/api/auth')?->getValue();
        self::assertNotNull($theirRefresh);

        $deactivated = $this->api('POST', '/api/users/'.$user['id'].'/deactivate');
        self::assertSame(200, $this->responseStatus());
        self::assertSame('deactivated', $deactivated['status']);

        $this->client->request('GET', '/api/auth/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$theirToken]);
        self::assertResponseStatusCodeSame(401);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_COOKIE' => 'kanso_refresh='.$theirRefresh]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(401, $this->login($user['email'], 'first password'));

        $this->api('POST', '/api/users/'.$user['id'].'/activate');
        self::assertSame(200, $this->responseStatus());
        self::assertSame(200, $this->login($user['email'], 'first password'));
    }

    public function testNobodyDeactivatesThemselves(): void
    {
        $email = $this->signInAs(Role::ADMIN);
        $me = $this->api('GET', '/api/users?q='.urlencode($email))['member'][0];

        $problem = $this->api('POST', '/api/users/'.$me['id'].'/deactivate');

        self::assertSame(409, $this->responseStatus());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('self', $problem['violations'][0]['code']);
    }

    public function testAnAdminSetsAForgottenPassword(): void
    {
        $this->signInAs(Role::ADMIN);
        $user = $this->newUser(Role::VIEWER);

        $this->api('POST', '/api/users/'.$user['id'].'/password', ['password' => 'short']);
        self::assertSame(422, $this->responseStatus());

        $this->api('POST', '/api/users/'.$user['id'].'/password', ['password' => 'a new password']);
        self::assertSame(200, $this->responseStatus());

        self::assertSame(401, $this->login($user['email'], 'first password'));
        self::assertSame(200, $this->login($user['email'], 'a new password'));
    }

    public function testInvalidInputListsEveryProblem(): void
    {
        $email = $this->signInAs(Role::ADMIN);

        $problem = $this->api('POST', '/api/users', ['email' => $email, 'role' => 'ROLE_ROOT', 'password' => 'short']);

        self::assertSame(422, $this->responseStatus());
        self::assertSame(
            [['email', 'duplicate'], ['role', 'unknown_role'], ['password', 'too_short']],
            array_map(static fn (array $v): array => [$v['path'], $v['code']], $problem['violations']),
        );

        $this->api('PATCH', '/api/users/0192f000-0000-7000-8000-000000000000', ['name' => 'Nobody']);
        self::assertSame(404, $this->responseStatus());
        $this->api('POST', '/api/users/0192f000-0000-7000-8000-000000000000/deactivate');
        self::assertSame(404, $this->responseStatus());
    }

    public function testOnlyAdminsManageUsers(): void
    {
        $this->signInAs(Role::ADMIN);
        $key = $this->api('POST', '/api/api-keys', ['name' => 'Integration', 'role' => Role::OPERATOR])['key'];
        $this->client->request('GET', '/api/users', server: ['HTTP_X_API_KEY' => $key]);
        self::assertResponseStatusCodeSame(403);

        $this->signInAs(Role::OPERATOR);
        $this->api('GET', '/api/users');
        self::assertSame(403, $this->responseStatus());
        $this->api('POST', '/api/users', ['email' => 'sneaky@example.com', 'role' => Role::ADMIN, 'password' => 'long enough']);
        self::assertSame(403, $this->responseStatus());
    }

    public function testEveryoneChangesTheirOwnPassword(): void
    {
        $email = $this->signInAs(Role::VIEWER);
        $oldRefresh = $this->client->getCookieJar()->get('kanso_refresh', '/api/auth')?->getValue();
        self::assertNotNull($oldRefresh);

        $problem = $this->api('POST', '/api/auth/password', ['currentPassword' => 'wrong', 'newPassword' => 'a new password']);
        self::assertSame(422, $this->responseStatus());
        self::assertSame('wrong_password', $problem['violations'][0]['code']);

        $tokens = $this->api('POST', '/api/auth/password', ['currentPassword' => 'secret', 'newPassword' => 'a new password']);
        self::assertSame(200, $this->responseStatus());
        self::assertIsString($tokens['accessToken']);
        $newRefresh = $this->client->getCookieJar()->get('kanso_refresh', '/api/auth')?->getValue();
        self::assertNotSame($oldRefresh, $newRefresh);

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_COOKIE' => 'kanso_refresh='.$oldRefresh]);
        self::assertResponseStatusCodeSame(401, 'the session from before the change is over');

        self::assertSame(401, $this->login($email, 'secret'));
        self::assertSame(200, $this->login($email, 'a new password'));
    }

    public function testAnApiKeyHasNoPasswordToChange(): void
    {
        $this->signInAs(Role::ADMIN);
        $key = $this->api('POST', '/api/api-keys', ['name' => 'Integration', 'role' => Role::OPERATOR])['key'];

        $this->client->request('POST', '/api/auth/password', server: ['HTTP_X_API_KEY' => $key, 'CONTENT_TYPE' => 'application/json'], content: '{"currentPassword":"x","newPassword":"long enough"}');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<mixed> the new user, whose password is "first password" */
    private function newUser(string $role): array
    {
        $user = $this->api('POST', '/api/users', ['email' => 'user-'.bin2hex(random_bytes(4)).'@example.com', 'role' => $role, 'password' => 'first password']);
        self::assertSame(201, $this->responseStatus());

        return $user;
    }

    /** Signs in without touching the admin's token; returns the status. */
    private function login(string $email, string $password): int
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR));

        return $this->responseStatus();
    }
}
