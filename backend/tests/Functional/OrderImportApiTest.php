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

    private const string ANNOTATED = "orderReference;customerName;shippingLine1;shippingPostalCode;shippingCity;shippingCountry;sku;quantity;unitPrice;paymentStatus;tags;note\n";

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

    public function testPaymentStatusTagsAndANoteAreSetOnTheNewOrderWithTheirEvents(): void
    {
        $csv = self::ANNOTATED
            ."WEB-1;Anna;Storgatan 1;111 22;Stockholm;SE;TEE-1;1;100;Paid;VIP | gift wrap|vip|;Leave at the door\n"
            ."WEB-1;;;;;;MUG-1;1;50;;;\n"
            ."WEB-2;Bo;Kungsgatan 2;411 19;Göteborg;SE;MUG-1;1;50;;;\n";

        self::assertSame([2, 0], [($r = $this->upload($csv))['created'], $r['failed']]);

        $order = $this->orderByReference('WEB-1');
        self::assertSame('paid', $order['paymentStatus']);
        self::assertSame(['gift wrap', 'VIP'], $order['tags'], 'split on "|", trimmed, the same tag once');
        self::assertSame(1, $order['version']);
        self::assertSame(
            [
                ['created', null, null],
                ['payment_status_changed', ['paymentStatus' => 'unpaid'], ['paymentStatus' => 'paid']],
                ['tags_changed', ['tags' => []], ['tags' => ['gift wrap', 'VIP']]],
                ['note', null, ['note' => 'Leave at the door']],
            ],
            array_map(static fn (array $event): array => [$event['type'], $event['before'], 'created' === $event['type'] ? null : $event['after']], $order['events']),
        );

        $plain = $this->orderByReference('WEB-2');
        self::assertSame(['unpaid', []], [$plain['paymentStatus'], $plain['tags']]);
        self::assertSame(['created'], array_column($plain['events'], 'type'), 'empty columns set nothing');

        $again = $this->upload(str_replace('Leave at the door', 'Changed note', str_replace('Paid', 'refunded', $csv)));
        self::assertSame([0, 2, 0], [$again['created'], $again['existing'], $again['failed']]);
        $order = $this->orderByReference('WEB-1');
        self::assertSame(['paid', 4], [$order['paymentStatus'], \count($order['events'])], 'an import never edits an existing order');
    }

    public function testBadPaymentStatusTagsAndNotesAreReportedInThePreviewAndSkipTheOrder(): void
    {
        $tooMany = implode('|', array_map(static fn (int $i): string => 'tag'.$i, range(1, 21)));
        $csv = self::ANNOTATED
            ."WEB-1;Anna;Storgatan 1;111 22;Stockholm;SE;TEE-1;1;100;free;;\n"                        // row 2
            ."WEB-2;Bo;Kungsgatan 2;411 19;Göteborg;SE;TEE-1;1;100;;\"a,b|ok\";\n"                  // row 3
            ."WEB-3;Cia;Vägen 3;123 45;Malmö;SE;TEE-1;1;100;;{$tooMany};\n"                          // row 4
            .'WEB-4;Dan;Vägen 3;123 45;Malmö;SE;TEE-1;1;100;;;'.str_repeat('x', 2001)."\n"          // row 5
            ."WEB-5;Eva;Gatan 4;123 45;Lund;SE;TEE-1;1;100;paid;a|b;Hi\n"                           // row 6
            ."WEB-5;;;;;;MUG-1;1;50;refunded;b|a;Hello\n"                                           // row 7: disagrees
            ."WEB-6;Fia;Gatan 6;123 45;Lund;SE;TEE-1;1;100;partially refunded;x;\n";                // row 8: fine

        foreach ([true, false] as $dryRun) {
            $result = $this->upload($csv, $dryRun);
            self::assertSame([6, 1, 5], [$result['orders'], $result['created'], $result['failed']]);
            self::assertSame(
                [
                    [2, 'WEB-1', 'paymentStatus', 'unknown_payment_status'],
                    [3, 'WEB-2', 'tags', 'tag'],
                    [4, 'WEB-3', 'tags', 'too_many_tags'],
                    [5, 'WEB-4', 'note', 'too_long'],
                    [7, 'WEB-5', 'paymentStatus', 'inconsistent'],
                    [7, 'WEB-5', 'tags', 'inconsistent'],
                    [7, 'WEB-5', 'note', 'inconsistent'],
                ],
                array_map(static fn (array $error): array => [$error['row'], $error['reference'], $error['field'], $error['code']], $result['errors']),
            );
        }

        self::assertSame(1, $this->api('GET', '/api/orders')['totalItems']);
        self::assertSame('partially_refunded', $this->orderByReference('WEB-6')['paymentStatus']);
    }

    public function testOrdersAreLinkedToTheCustomerWithTheirEmailAndTheSameFileTwiceDuplicatesNothing(): void
    {
        $anna = (string) $this->api('POST', '/api/customers', ['email' => 'anna@example.com', 'name' => 'Anna Andersson'])['id'];
        $csv = self::HEADER
            ."WEB-1;Anna A;ANNA@example.com;Storgatan 1;111 22;Stockholm;SE;TEE-1;;1;100\n"
            ."WEB-2;Bo Berg;bo@example.com;Kungsgatan 2;411 19;Göteborg;SE;TEE-1;;1;100\n"
            ."WEB-2;;;;;;;MUG-1;;1;50\n"
            ."WEB-3;Bo Berg;Bo@Example.com;Kungsgatan 2;411 19;Göteborg;SE;MUG-1;;2;50\n"
            ."WEB-4;Cia;;Vägen 3;123 45;Malmö;SE;MUG-1;;1;50\n";

        $preview = $this->upload($csv, dryRun: true);
        self::assertSame([4, 1], [$preview['created'], $preview['newCustomers']]);
        self::assertSame(1, $this->api('GET', '/api/customers')['totalItems']);

        $result = $this->upload($csv);
        self::assertSame([4, 1], [$result['created'], $result['newCustomers']]);

        $customers = $this->api('GET', '/api/customers');
        self::assertSame(2, $customers['totalItems']);
        $bo = array_column($customers['member'], 'id', 'email')['bo@example.com'] ?? null;
        self::assertIsString($bo, 'Bo is created with the email as the first order wrote it.');
        self::assertSame('Bo Berg', $this->api('GET', '/api/customers/'.$bo)['name']);

        self::assertSame($anna, $this->orderByReference('WEB-1')['customer']['id']);
        self::assertSame('Anna A', $this->orderByReference('WEB-1')['customer']['name'], 'The order keeps the name the file gave it.');
        self::assertNull($this->orderByReference('WEB-4')['customer']['id'] ?? null, 'An order without an email is not linked.');
        self::assertSame(['WEB-2', 'WEB-3'], $this->referencesOf($bo));
        self::assertSame(['WEB-1'], $this->referencesOf($anna));

        $again = $this->upload($csv);

        self::assertSame([0, 4, 0], [$again['created'], $again['existing'], $again['newCustomers']]);
        self::assertSame(2, $this->api('GET', '/api/customers')['totalItems']);
        self::assertSame(['WEB-2', 'WEB-3'], $this->referencesOf($bo));
        self::assertSame(['WEB-1'], $this->referencesOf($anna));
    }

    public function testAFailedOrderCreatesNoCustomer(): void
    {
        $result = $this->upload(self::HEADER."WEB-1;Bo Berg;bo@example.com;Kungsgatan 2;411 19;Göteborg;SE;NOPE;;1;100\n");

        self::assertSame([0, 1, 0], [$result['created'], $result['failed'], $result['newCustomers']]);
        self::assertSame(0, $this->api('GET', '/api/customers')['totalItems']);
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

    /** @return list<string> the external references of the customer's orders, sorted */
    private function referencesOf(string $customerId): array
    {
        $references = array_column($this->api('GET', '/api/orders?customer='.$customerId)['member'], 'externalReference');
        sort($references);

        return $references;
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
