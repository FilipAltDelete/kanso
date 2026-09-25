<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** POST /api/order-imports: rows grouped into orders, the preview, and importing the same file again. */
final class OrderImportApiTest extends WebTestCase
{
    use SignsIn;

    private const string HEADER = "orderReference;customerName;customerEmail;shippingLine1;shippingPostalCode;shippingCity;shippingCountry;sku;lineName;quantity;unitPrice\n";

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);

        $catalog = static::getContainer()->get(CatalogService::class);
        self::assertInstanceOf(CatalogService::class, $catalog);
        $catalog->createLocation('WH1', 'Main', new Address());
        $catalog->createProduct('TEE-1', 'T-shirt', null, null);
        $catalog->createProduct('MUG-1', 'Mug', null, null);
    }

    public function testRowsWithTheSameReferenceBecomeOneOrder(): void
    {
        $csv = self::HEADER
            ."WEB-1;Anna Andersson;anna@example.com;Storgatan 1;111 22;Stockholm;se;TEE-1;;2;199,00\n"
            ."WEB-1;;;;;;;MUG-1;Mug, blue;1;89.5\n"
            ."WEB-2;Bo Berg;;Kungsgatan 2;411 19;Göteborg;SE;MUG-1;;3;89\n";

        $result = $this->upload($csv);

        self::assertSame(200, $this->responseStatus());
        self::assertSame([false, 3, 2, 2, 0, 0], [$result['dryRun'], $result['rows'], $result['orders'], $result['created'], $result['existing'], $result['failed']]);

        $order = $this->orderByReference('WEB-1');
        self::assertSame('Anna Andersson', $order['customer']['name']);
        self::assertSame('SE', $order['shippingAddress']['countryCode']);
        self::assertSame([['TEE-1', 'T-shirt', 2, 19_900], ['MUG-1', 'Mug, blue', 1, 8_950]], array_map(static fn (array $line): array => [$line['sku'], $line['name'], $line['quantity'], $line['unitPrice']], $order['lines']));
        self::assertSame(48_750, $order['total']);
        self::assertSame('pending', $order['status']);
    }

    public function testThePreviewWritesNothingAndTheSameFileTwiceCreatesNothingNew(): void
    {
        $csv = self::HEADER."WEB-1;Anna;;Storgatan 1;111 22;Stockholm;SE;TEE-1;;1;100\n";

        $preview = $this->upload($csv, dryRun: true);
        self::assertSame([true, 1], [$preview['dryRun'], $preview['created']]);
        self::assertSame(0, $this->api('GET', '/api/orders?q=Anna')['totalItems']);

        self::assertSame(1, $this->upload($csv)['created']);
        $again = $this->upload($csv);

        self::assertSame([0, 1], [$again['created'], $again['existing']]);
        self::assertSame(1, $this->api('GET', '/api/orders?q=Anna')['totalItems']);
    }

    public function testAnOrderWithAnyProblemIsSkippedWholeAndReportedAtItsRows(): void
    {
        $csv = self::HEADER
            ."WEB-1;Anna;;Storgatan 1;111 22;Stockholm;SE;TEE-1;;1;100\n"   // row 2
            ."WEB-1;Anna;;Storgatan 1;111 22;Stockholm;SE;NOPE;;1;100\n"    // row 3: unknown SKU
            ."WEB-2;Bo;;Kungsgatan 2;411 19;Göteborg;SE;TEE-1;;x;1.999\n"   // row 4: quantity and price
            ."WEB-3;Cia;;Vägen 3;123 45;Malmö;SE;MUG-1;;1;50\n"              // row 5
            ."WEB-3;Dan;;Vägen 3;123 45;Malmö;SE;TEE-1;;1;50\n"              // row 6: another customer
            ."WEB-4;Eva;;Gatan 4;123 45;Lund;SE;MUG-1;;1;50\n";              // row 7: fine

        $result = $this->upload($csv);

        self::assertSame([4, 1, 3], [$result['orders'], $result['created'], $result['failed']]);
        self::assertSame(
            [[3, 'WEB-1', 'sku', 'unknown_sku'], [4, 'WEB-2', 'quantity', 'integer'], [4, 'WEB-2', 'unitPrice', 'price'], [6, 'WEB-3', 'customerName', 'inconsistent']],
            array_map(static fn (array $error): array => [$error['row'], $error['reference'], $error['field'], $error['code']], $result['errors']),
        );
        self::assertSame(1, $this->api('GET', '/api/orders?q=Eva')['totalItems']);
        self::assertSame(0, $this->api('GET', '/api/orders?q=Anna')['totalItems'], 'Not created with only its good line.');
    }

    public function testTheReferenceIsUniquePerChannelThroughTheApiToo(): void
    {
        $body = ['externalReference' => 'WEB-9', 'customer' => ['name' => 'Anna'], 'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'], 'lines' => [['sku' => 'TEE-1', 'quantity' => 1, 'unitPrice' => 100]]];

        $created = $this->api('POST', '/api/orders', $body);
        self::assertSame(201, $this->responseStatus());
        self::assertSame('WEB-9', $created['externalReference']);

        $duplicate = $this->api('POST', '/api/orders', $body);
        self::assertSame(422, $this->responseStatus());
        self::assertSame(['externalReference', 'taken'], [$duplicate['violations'][0]['path'], $duplicate['violations'][0]['code']]);

        $import = $this->upload(self::HEADER."web-9;Anna;;Storgatan 1;111 22;Stockholm;SE;TEE-1;;1;1\n", dryRun: true);
        self::assertSame(1, $import['existing'], 'References compare without case, like the column.');
    }

    public function testAViewerCannotImport(): void
    {
        $this->signInAs(Role::VIEWER);

        $this->upload(self::HEADER."WEB-1;Anna;;Storgatan 1;111 22;Stockholm;SE;TEE-1;;1;100\n", dryRun: true);

        self::assertSame(403, $this->responseStatus());
    }

    /** @return array<mixed> */
    private function orderByReference(string $reference): array
    {
        $page = $this->api('GET', '/api/orders?q='.$reference);
        self::assertSame(1, $page['totalItems']);

        return $this->api('GET', '/api/orders/'.$page['member'][0]['id']);
    }

    /** @return array<mixed> */
    private function upload(string $csv, bool $dryRun = false): array
    {
        $this->client->request('POST', '/api/order-imports'.($dryRun ? '?dryRun=true' : ''), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'text/csv',
        ], content: $csv);

        return $this->json();
    }
}
