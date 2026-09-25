<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Application\Security\ApiKeyService;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $operatorEmail = '';
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        [$this->operatorEmail, $this->token] = $this->signIn(Role::OPERATOR);
    }

    public function testCreateAndReadACustomer(): void
    {
        $email = self::uniqueEmail('Anna.Svensson');

        $created = $this->send('POST', '/api/customers', [
            'email' => $email,
            'name' => 'Anna Svensson',
            'phone' => '+46 70 123 45 67',
            'addresses' => [
                ['type' => 'shipping', 'line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'se'],
                ['type' => 'shipping', 'line1' => 'Lillgatan 2', 'postalCode' => '222 33', 'city' => 'Lund', 'countryCode' => 'SE', 'isDefault' => true],
                ['type' => 'billing', 'company' => 'Svensson AB', 'line1' => 'Box 12', 'postalCode' => '111 00', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($email, $created['email']);
        self::assertSame('+46 70 123 45 67', $created['phone']);
        self::assertCount(3, $created['addresses']);
        self::assertSame('SE', $created['addresses'][0]['countryCode'], 'country codes are upper-cased');
        self::assertSame([false, true, true], array_column($created['addresses'], 'isDefault'), 'the marked shipping default is kept; billing gets its only address');

        $read = $this->send('GET', '/api/customers/'.$created['id']);
        self::assertResponseIsSuccessful();
        self::assertSame($created['addresses'], $read['addresses']);
    }

    public function testTheEmailIsUniqueRegardlessOfCase(): void
    {
        $email = self::uniqueEmail('dup');
        $this->send('POST', '/api/customers', ['email' => $email, 'name' => 'First']);
        self::assertResponseStatusCodeSame(201);

        $problem = $this->send('POST', '/api/customers', ['email' => strtoupper($email), 'name' => 'Second']);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame([['path' => 'email', 'message' => 'A customer with this email already exists.', 'code' => 'duplicate']], $problem['violations']);
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        $problem = $this->send('POST', '/api/customers', [
            'email' => 'not-an-email',
            'name' => '  ',
            'phone' => 'call me',
            'addresses' => [
                ['type' => 'home', 'line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'XX'],
                ['type' => 'billing', 'isDefault' => true, 'line1' => 'A', 'postalCode' => '1', 'city' => 'B', 'countryCode' => 'SE'],
                ['type' => 'billing', 'isDefault' => true, 'postalCode' => '1', 'city' => 'B', 'countryCode' => 'SE'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing([
            'email' => 'invalid_email',
            'name' => 'required',
            'phone' => 'invalid_phone',
            'addresses[0].type' => 'invalid_choice',
            'addresses[0].countryCode' => 'invalid_country',
            'addresses[2].line1' => 'required',
        ], array_column($problem['violations'], 'code', 'path'));
    }

    public function testTwoDefaultsOfOneTypeAreRefused(): void
    {
        $address = ['type' => 'billing', 'isDefault' => true, 'line1' => 'A', 'postalCode' => '1', 'city' => 'B', 'countryCode' => 'SE'];

        $problem = $this->send('POST', '/api/customers', ['email' => self::uniqueEmail('defaults'), 'name' => 'Two defaults', 'addresses' => [$address, $address]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('addresses[1].isDefault', $problem['violations'][0]['path']);
        self::assertSame('duplicate_default', $problem['violations'][0]['code']);
    }

    public function testSearchByNameOrEmailWithPaging(): void
    {
        $marker = bin2hex(random_bytes(4));
        foreach (['Björn '.$marker, 'Cecilia '.$marker, 'Anders '.$marker] as $name) {
            $this->send('POST', '/api/customers', ['email' => self::uniqueEmail('search'), 'name' => $name]);
        }
        $this->send('POST', '/api/customers', ['email' => 'x-'.$marker.'@example.com', 'name' => 'By email only']);

        $byName = $this->send('GET', '/api/customers?q='.strtoupper($marker).'&order[name]=asc&itemsPerPage=2');
        self::assertResponseIsSuccessful();
        self::assertSame(4, $byName['totalItems']);
        self::assertSame(['Anders '.$marker, 'Björn '.$marker], array_column($byName['member'], 'name'));

        $page2 = $this->send('GET', '/api/customers?q='.$marker.'&order[name]=asc&itemsPerPage=2&page=2');
        self::assertSame(['By email only', 'Cecilia '.$marker], array_column($page2['member'], 'name'));

        $byEmail = $this->send('GET', '/api/customers?q=X-'.$marker);
        self::assertSame(['By email only'], array_column($byEmail['member'], 'name'));
    }

    public function testAnUnknownSortIsRefused(): void
    {
        $this->send('GET', '/api/customers?order[password]=asc');

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateKeepsAddressesSentBackAndRecordsTheChange(): void
    {
        $created = $this->send('POST', '/api/customers', [
            'email' => self::uniqueEmail('update'),
            'name' => 'Old Name',
            'addresses' => [
                ['type' => 'shipping', 'line1' => 'Kept 1', 'postalCode' => '1', 'city' => 'A', 'countryCode' => 'SE'],
                ['type' => 'shipping', 'line1' => 'Removed 2', 'postalCode' => '2', 'city' => 'B', 'countryCode' => 'SE'],
            ],
        ]);
        $kept = $created['addresses'][0];

        $updated = $this->send('PATCH', '/api/customers/'.$created['id'], [
            'name' => 'New Name',
            'addresses' => [
                [...$kept, 'line1' => 'Kept 1, floor 2'],
                ['type' => 'billing', 'line1' => 'New 3', 'postalCode' => '3', 'city' => 'C', 'countryCode' => 'NO'],
            ],
        ], 'application/merge-patch+json');

        self::assertResponseIsSuccessful();
        self::assertSame('New Name', $updated['name']);
        self::assertSame($created['email'], $updated['email'], 'a merge patch leaves out what it does not send');
        self::assertSame($kept['id'], $updated['addresses'][0]['id']);
        self::assertSame('Kept 1, floor 2', $updated['addresses'][0]['line1']);
        self::assertSame(['Kept 1, floor 2', 'New 3'], array_column($updated['addresses'], 'line1'));

        $history = $this->send('GET', '/api/customers/'.$created['id'].'/history');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $history['totalItems']);
        [$latest, $first] = $history['member'];
        self::assertSame('updated', $latest['type']);
        self::assertSame($this->operatorEmail, $latest['actor']);
        // A JSON column keeps no key order, so compare as maps.
        self::assertEquals(['before' => 'Old Name', 'after' => 'New Name'], $latest['changes']['name']);
        self::assertArrayHasKey('addresses', $latest['changes']);
        self::assertArrayNotHasKey('email', $latest['changes']);
        self::assertSame('created', $first['type']);
        self::assertEquals(['before' => null, 'after' => 'Old Name'], $first['changes']['name']);
    }

    public function testAnUpdateThatChangesNothingRecordsNothing(): void
    {
        $created = $this->send('POST', '/api/customers', ['email' => self::uniqueEmail('noop'), 'name' => 'Same']);

        $this->send('PATCH', '/api/customers/'.$created['id'], ['name' => 'Same'], 'application/merge-patch+json');
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->send('GET', '/api/customers/'.$created['id'].'/history')['totalItems']);
    }

    public function testAnAddressOfAnotherCustomerCannotBeTakenOver(): void
    {
        $other = $this->send('POST', '/api/customers', [
            'email' => self::uniqueEmail('other'),
            'name' => 'Other',
            'addresses' => [['type' => 'shipping', 'line1' => 'Theirs', 'postalCode' => '1', 'city' => 'A', 'countryCode' => 'SE']],
        ]);
        $mine = $this->send('POST', '/api/customers', ['email' => self::uniqueEmail('mine'), 'name' => 'Mine']);

        $problem = $this->send('PATCH', '/api/customers/'.$mine['id'], ['addresses' => $other['addresses']], 'application/merge-patch+json');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_address', $problem['violations'][0]['code']);
    }

    public function testUnknownCustomersAreNotFound(): void
    {
        $this->send('GET', '/api/customers/01928c6a-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);

        $this->send('GET', '/api/customers/01928c6a-0000-7000-8000-000000000000/history');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAViewerCanReadButNotWrite(): void
    {
        $created = $this->send('POST', '/api/customers', ['email' => self::uniqueEmail('viewer'), 'name' => 'Read me']);
        [, $this->token] = $this->signIn(Role::VIEWER);

        $this->send('GET', '/api/customers/'.$created['id']);
        self::assertResponseIsSuccessful();

        $this->send('POST', '/api/customers', ['email' => self::uniqueEmail('viewer'), 'name' => 'Nope']);
        self::assertResponseStatusCodeSame(403);

        $this->send('PATCH', '/api/customers/'.$created['id'], ['name' => 'Nope'], 'application/merge-patch+json');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnApiKeyIsRecordedAsTheActor(): void
    {
        $keys = static::getContainer()->get(ApiKeyService::class);
        self::assertInstanceOf(ApiKeyService::class, $keys);
        ['plainKey' => $plainKey] = $keys->create('Shopify sync', Role::OPERATOR);

        $this->client->request('POST', '/api/customers', server: [
            'HTTP_X_API_KEY' => $plainKey,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], content: json_encode(['email' => self::uniqueEmail('shop'), 'name' => 'From Shopify'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];

        self::assertSame('API key: Shopify sync', $this->send('GET', '/api/customers/'.$id.'/history')['member'][0]['actor']);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, ?array $body = null, string $contentType = 'application/json'): array
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => $contentType,
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->json();
    }

    /** @return array{string, string} the user's email and an access token */
    private function signIn(string $role): array
    {
        $email = self::uniqueEmail('user');
        $users = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $users->create($email, 'secret', [$role]);

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => 'secret'], \JSON_THROW_ON_ERROR));
        $token = $this->json()['accessToken'] ?? null;
        self::assertIsString($token);

        return [$email, $token];
    }

    private static function uniqueEmail(string $local): string
    {
        return $local.'-'.bin2hex(random_bytes(4)).'@example.com';
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
