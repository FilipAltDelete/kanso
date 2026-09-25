<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Products and locations through the API. */
final class CatalogApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);
    }

    public function testAProductIsCreatedAndReadBack(): void
    {
        $sku = 'TEE-'.bin2hex(random_bytes(3));
        $created = $this->api('POST', '/api/products', ['sku' => " $sku ", 'name' => 'Tee', 'barcode' => '7350000000001', 'weightGrams' => 180]);

        self::assertSame(201, $this->responseStatus());
        self::assertSame($sku, $created['sku'], 'Surrounding spaces are trimmed.');

        $read = $this->api('GET', '/api/products/'.$created['id']);
        self::assertSame(['Tee', '7350000000001', 180, 1, 0], [$read['name'], $read['barcode'], $read['weightGrams'], $read['version'], $read['onHand']]);
    }

    public function testASkuIsUniqueAndValidated(): void
    {
        $sku = 'TEE-'.bin2hex(random_bytes(3));
        $this->api('POST', '/api/products', ['sku' => $sku, 'name' => 'Tee']);

        $duplicate = $this->api('POST', '/api/products', ['sku' => $sku, 'name' => 'Another']);
        self::assertSame(422, $this->responseStatus());
        self::assertSame('taken', $duplicate['violations'][0]['code']);

        $invalid = $this->api('POST', '/api/products', ['sku' => 'has space', 'name' => '', 'weightGrams' => -1]);
        self::assertSame(['sku', 'name', 'weightGrams'], array_column($invalid['violations'], 'path'));
    }

    public function testAnEditNeedsTheCurrentVersionAndKeepsWhatWasNotSent(): void
    {
        $id = $this->api('POST', '/api/products', ['sku' => 'TEE-'.bin2hex(random_bytes(3)), 'name' => 'Tee', 'barcode' => '123'])['id'];

        $edited = $this->api('PATCH', '/api/products/'.$id, ['name' => 'Better tee', 'version' => 1]);
        self::assertSame(200, $this->responseStatus());
        self::assertSame(['Better tee', '123', 2], [$edited['name'], $edited['barcode'], $edited['version']]);

        $this->api('PATCH', '/api/products/'.$id, ['name' => 'Lost update', 'version' => 1]);
        self::assertSame(409, $this->responseStatus());

        $this->api('PATCH', '/api/products/'.$id, ['barcode' => null, 'version' => 2]);
        self::assertNull($this->api('GET', '/api/products/'.$id)['barcode'], 'null clears a field.');

        $this->api('PATCH', '/api/products/'.$id, ['name' => 'No version']);
        self::assertSame(422, $this->responseStatus());
    }

    public function testProductsAreSearchedSortedAndPaged(): void
    {
        $prefix = 'SRCH'.bin2hex(random_bytes(2));
        foreach (['B', 'A', 'C'] as $suffix) {
            $this->api('POST', '/api/products', ['sku' => $prefix.'-'.$suffix, 'name' => 'Item '.$suffix]);
        }

        $page = $this->api('GET', "/api/products?q=$prefix&sort=-sku&itemsPerPage=2");

        self::assertSame(3, $page['totalItems']);
        self::assertSame([$prefix.'-C', $prefix.'-B'], array_column($page['member'], 'sku'));
        self::assertSame([$prefix.'-A'], array_column($this->api('GET', "/api/products?q=$prefix&sort=-sku&itemsPerPage=2&page=2")['member'], 'sku'));
    }

    public function testASearchWildcardIsText(): void
    {
        $this->api('POST', '/api/products', ['sku' => 'PCT-'.bin2hex(random_bytes(3)), 'name' => '100% cotton']);

        self::assertSame(0, $this->api('GET', '/api/products?q=%25%25nothing')['totalItems']);
    }

    public function testALocationIsCreatedWithItsAddress(): void
    {
        $code = 'WH-'.bin2hex(random_bytes(3));
        $created = $this->api('POST', '/api/locations', ['code' => $code, 'name' => 'Main', 'addressLine1' => 'Hamngatan 1', 'postalCode' => '411 06', 'city' => 'Göteborg', 'countryCode' => 'se']);

        self::assertSame(201, $this->responseStatus());
        self::assertSame(['Göteborg', 'SE'], [$created['city'], $created['countryCode']]);

        $this->api('POST', '/api/locations', ['code' => $code, 'name' => 'Again']);
        self::assertSame(422, $this->responseStatus());

        $this->api('POST', '/api/locations', ['code' => 'bad code', 'name' => 'X', 'countryCode' => 'Sweden']);
        self::assertSame(['code', 'countryCode'], array_column($this->json()['violations'], 'path'));
    }

    public function testAViewerCannotCreate(): void
    {
        $this->signInAs(Role::VIEWER);

        $this->api('POST', '/api/products', ['sku' => 'NOPE-'.bin2hex(random_bytes(3)), 'name' => 'Tee']);
        self::assertSame(403, $this->responseStatus());

        $this->api('GET', '/api/products');
        self::assertSame(200, $this->responseStatus());
    }

    public function testTheCatalogNeedsSigningIn(): void
    {
        $this->client->request('GET', '/api/products');

        self::assertSame(401, $this->responseStatus());
    }
}
