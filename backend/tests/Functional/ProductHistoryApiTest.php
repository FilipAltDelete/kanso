<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** GET /api/product-events: who changed a product, when, through what, and what changed. */
final class ProductHistoryApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testCreatesAndEditsAreRecordedWithWhoAndWhat(): void
    {
        $email = $this->signInAs(Role::OPERATOR);
        $id = $this->api('POST', '/api/products', ['sku' => 'TEE-1', 'name' => 'Tee', 'barcode' => '123'])['id'];
        $this->api('PATCH', '/api/products/'.$id, ['name' => 'Better tee', 'barcode' => null, 'version' => 1]);

        $history = $this->api('GET', '/api/product-events?product='.$id);

        self::assertSame(2, $history['totalItems']);
        [$updated, $created] = $history['member'];
        self::assertSame(['updated', 'api', $email], [$updated['type'], $updated['source'], $updated['actorName']]);
        self::assertSame(['sku' => 'TEE-1', 'name' => 'Tee', 'barcode' => '123', 'weightGrams' => null], $updated['before']);
        self::assertSame(['sku' => 'TEE-1', 'name' => 'Better tee', 'barcode' => null, 'weightGrams' => null], $updated['after']);
        self::assertSame(['created', null], [$created['type'], $created['before']]);
    }

    public function testAnEditThatChangesNothingRecordsNothing(): void
    {
        $this->signInAs(Role::OPERATOR);
        $id = $this->api('POST', '/api/products', ['sku' => 'TEE-1', 'name' => 'Tee'])['id'];

        $this->api('PATCH', '/api/products/'.$id, ['name' => 'Tee', 'version' => 1]);

        self::assertSame(1, $this->api('GET', '/api/product-events?product='.$id)['totalItems']);
    }

    public function testAnImportIsRecordedAsSuchAndAnUnchangedRowIsNot(): void
    {
        $this->signInAs(Role::OPERATOR);
        $this->upload("sku,name\nTEE-1,Tee\n");
        $this->upload("sku,name\nTEE-1,Tee\n");
        $this->upload("sku,name\nTEE-1,Imported tee\n");
        $id = $this->api('GET', '/api/products?q=TEE-1')['member'][0]['id'];

        $history = $this->api('GET', '/api/product-events?product='.$id)['member'];

        self::assertSame([['updated', 'import'], ['created', 'import']], array_map(static fn (array $event): array => [$event['type'], $event['source']], $history));
        self::assertSame('Imported tee', $history[0]['after']['name']);
    }

    public function testAViewerCanReadTheHistory(): void
    {
        $this->signInAs(Role::OPERATOR);
        $id = $this->api('POST', '/api/products', ['sku' => 'TEE-1', 'name' => 'Tee'])['id'];
        $this->signInAs(Role::VIEWER);

        $this->api('GET', '/api/product-events?product='.$id);

        self::assertSame(200, $this->responseStatus());
    }

    private function upload(string $csv): void
    {
        $this->client->request('POST', '/api/product-imports', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'text/csv',
        ], content: $csv);
        self::assertSame(200, $this->responseStatus());
    }
}
