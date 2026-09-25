<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** GET /api/import-runs: every import run for real, with who, when, which file and what came of it. */
final class ImportHistoryApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $email;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->email = $this->signInAs(Role::OPERATOR);
    }

    public function testAnImportIsRecordedWithItsCountsAndProblemsButAPreviewIsNot(): void
    {
        $csv = "sku,name\nTEE-1,Tee\nbad sku,Bad\n";
        $preview = $this->upload('products', $csv, dryRun: true);
        self::assertNull($preview['importRunId']);

        $result = $this->upload('products', $csv, filename: 'C:\\fakepath\\products.csv');
        self::assertNotNull($result['importRunId']);

        $list = $this->api('GET', '/api/import-runs?type=products');
        self::assertSame(1, $list['totalItems']);
        $run = $list['member'][0];
        self::assertSame(['products', 'products.csv', $this->email, 1], [$run['type'], $run['filename'], $run['actorName'], $run['errorCount']]);
        // Equals, not same: a JSON column does not keep key order.
        self::assertEquals(['rows' => 2, 'created' => 1, 'updated' => 0, 'unchanged' => 0, 'failed' => 1], $run['counts']);
        self::assertArrayNotHasKey('errors', $run, 'The list leaves out the failed rows.');

        $detail = $this->api('GET', '/api/import-runs/'.$result['importRunId']);
        self::assertSame([[3, 'bad sku', 'sku', 'format']], array_map(static fn (array $error): array => [$error['row'], $error['sku'], $error['field'], $error['code']], $detail['errors']));
    }

    public function testOrderImportsAreRecordedAndListedByType(): void
    {
        $this->upload('products', "sku,name\nTEE-1,Tee\n");
        $this->api('POST', '/api/locations', ['code' => 'WH1', 'name' => 'Main']);
        $this->upload('orders', "orderReference;customerName;shippingLine1;shippingPostalCode;shippingCity;shippingCountry;sku;quantity;unitPrice\nWEB-1;Anna;Storgatan 1;111 22;Stockholm;SE;TEE-1;1;100\n", filename: 'orders.csv');

        $orders = $this->api('GET', '/api/import-runs?type=orders');

        self::assertSame(1, $orders['totalItems']);
        self::assertSame(['orders.csv', 1, 1], [$orders['member'][0]['filename'], $orders['member'][0]['counts']['orders'], $orders['member'][0]['counts']['created']]);
        self::assertSame(2, $this->api('GET', '/api/import-runs')['totalItems']);
    }

    public function testAFileThatIsRefusedIsNotRecorded(): void
    {
        $this->upload('products', "sku,colour\nA,red\n");

        self::assertSame(0, $this->api('GET', '/api/import-runs')['totalItems']);
    }

    /** @return array<mixed> */
    private function upload(string $type, string $csv, bool $dryRun = false, ?string $filename = null): array
    {
        $query = http_build_query(array_filter(['dryRun' => $dryRun ? 'true' : null, 'filename' => $filename]));
        $path = ['products' => '/api/product-imports', 'orders' => '/api/order-imports'][$type];
        $this->client->request('POST', $path.('' === $query ? '' : '?'.$query), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'text/csv',
        ], content: $csv);

        return $this->json();
    }
}
