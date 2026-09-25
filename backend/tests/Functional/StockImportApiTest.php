<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** POST /api/stock-imports: the preview, the import, its movements and history, and the same file again. */
final class StockImportApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $sku;
    private string $productId;
    private string $code;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);

        $this->sku = 'STK-'.bin2hex(random_bytes(3));
        $this->code = 'WH-'.bin2hex(random_bytes(3));
        $this->productId = (string) $this->api('POST', '/api/products', ['sku' => $this->sku, 'name' => 'Tee'])['id'];
        $this->api('POST', '/api/locations', ['code' => $this->code, 'name' => 'Main']);
    }

    public function testAPreviewWritesNothingAndTheImportSetsOnHandWithACountMovement(): void
    {
        $csv = "sku;location;quantity\n{$this->sku};{$this->code};12\nNOPE-{$this->sku};{$this->code};1\n";

        $preview = $this->upload($csv, dryRun: true);
        self::assertSame(200, $this->responseStatus());
        self::assertSame([true, 2, 1, 0, 1, null], [$preview['dryRun'], $preview['rows'], $preview['changed'], $preview['unchanged'], $preview['failed'], $preview['importRunId']]);
        self::assertSame([[3, 'sku', 'not_found']], array_map(static fn (array $error): array => [$error['row'], $error['field'], $error['code']], $preview['errors']));
        self::assertSame(0, $this->api('GET', '/api/inventory-levels?product='.$this->productId)['totalItems']);

        $import = $this->upload($csv, filename: 'stock.csv');
        self::assertSame([false, 1, 1], [$import['dryRun'], $import['changed'], $import['failed']]);
        self::assertNotNull($import['importRunId']);

        self::assertSame(12, $this->api('GET', '/api/inventory-levels?product='.$this->productId)['member'][0]['onHand']);
        $movements = $this->api('GET', '/api/inventory-movements?product='.$this->productId)['member'];
        self::assertSame([['adjustment', 'count', 0, 12]], array_map(static fn (array $movement): array => [$movement['type'], $movement['reason'], $movement['onHandBefore'], $movement['onHandAfter']], $movements));

        $run = $this->api('GET', '/api/import-runs/'.$import['importRunId']);
        self::assertSame(['stock', 'stock.csv', 1], [$run['type'], $run['filename'], $run['errorCount']]);
        self::assertEquals(['rows' => 2, 'changed' => 1, 'unchanged' => 0, 'failed' => 1], $run['counts']);
        self::assertSame(1, $this->api('GET', '/api/import-runs?type=stock')['totalItems']);
    }

    public function testTheSameFileAgainChangesNothingEvenSpelledDifferently(): void
    {
        $this->upload("sku,location,quantity\n{$this->sku},{$this->code},7\n");

        $again = $this->upload("sku,location,quantity\n".strtolower($this->sku).','.strtolower($this->code).",7\n");

        self::assertSame([0, 1, 0], [$again['changed'], $again['unchanged'], $again['failed']]);
        self::assertSame(1, $this->api('GET', '/api/inventory-movements?product='.$this->productId)['totalItems']);
        self::assertSame(1, $this->api('GET', '/api/inventory-levels?product='.$this->productId)['member'][0]['version']);
    }

    public function testABadHeaderIsA422AndAViewerCannotImport(): void
    {
        $problem = $this->upload("sku,location,qty\nA,B,1\n");
        self::assertSame(422, $this->responseStatus());
        self::assertSame(['header.qty', 'header.quantity'], array_column($problem['violations'], 'path'));

        $this->signInAs(Role::VIEWER);
        $this->upload("sku,location,quantity\n{$this->sku},{$this->code},1\n", dryRun: true);
        self::assertSame(403, $this->responseStatus());
    }

    /** @return array<mixed> */
    private function upload(string $csv, bool $dryRun = false, ?string $filename = null): array
    {
        $query = http_build_query(array_filter(['dryRun' => $dryRun ? 'true' : null, 'filename' => $filename]));
        $this->client->request('POST', '/api/stock-imports'.('' === $query ? '' : '?'.$query), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'text/csv',
        ], content: $csv);

        return $this->json();
    }
}
