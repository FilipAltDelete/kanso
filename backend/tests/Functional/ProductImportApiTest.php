<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** POST /api/product-imports: the preview, the import, and importing the same file again. */
final class ProductImportApiTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);
        $this->prefix = 'IMP'.bin2hex(random_bytes(3));
    }

    public function testAPreviewWritesNothingAndTheImportWritesWhatItShowed(): void
    {
        $csv = "sku;name;barcode;weightGrams\n{$this->prefix}-1;Tee;7350000000001;180\n{$this->prefix}-2;Mug;;\nbad sku;;;\n";

        $preview = $this->upload($csv, dryRun: true);
        self::assertSame(200, $this->responseStatus());
        self::assertSame([true, 3, 2, 0, 1], [$preview['dryRun'], $preview['rows'], $preview['created'], $preview['updated'], $preview['failed']]);
        self::assertSame([4, 4], array_column($preview['errors'], 'row'));
        self::assertSame(0, $this->api('GET', "/api/products?q={$this->prefix}")['totalItems']);

        $import = $this->upload($csv);
        self::assertSame([false, 2, 1], [$import['dryRun'], $import['created'], $import['failed']]);

        $read = $this->api('GET', "/api/products?q={$this->prefix}&sort=sku")['member'];
        self::assertSame([["{$this->prefix}-1", 'Tee', '7350000000001', 180], ["{$this->prefix}-2", 'Mug', null, null]], array_map(static fn (array $product): array => [$product['sku'], $product['name'], $product['barcode'], $product['weightGrams']], $read));
    }

    public function testTheSameFileAgainChangesNothingAndAnEditedOneUpdates(): void
    {
        $csv = "sku,name\n{$this->prefix}-1,Tee\n";
        $this->upload($csv);
        $version = $this->api('GET', "/api/products?q={$this->prefix}")['member'][0]['version'];

        $again = $this->upload($csv);
        self::assertSame([0, 0, 1], [$again['created'], $again['updated'], $again['unchanged']]);
        self::assertSame($version, $this->api('GET', "/api/products?q={$this->prefix}")['member'][0]['version'], 'Not written at all.');

        $edited = $this->upload("sku,name\n{$this->prefix}-1,Better tee\n");
        self::assertSame(1, $edited['updated']);
        self::assertSame('Better tee', $this->api('GET', "/api/products?q={$this->prefix}")['member'][0]['name']);
    }

    public function testASkuThatDiffersOnlyInCaseFromAnExistingOneIsRefused(): void
    {
        $this->api('POST', '/api/products', ['sku' => "{$this->prefix}-Lös", 'name' => 'Tomdosa']);

        $result = $this->upload("sku,name\n".strtolower($this->prefix).'-los,Tomdosa'."\n");

        self::assertSame([0, 1], [$result['created'], $result['failed']]);
        self::assertSame('spelling', $result['errors'][0]['code']);
    }

    public function testAFileWithABadHeaderIsA422(): void
    {
        $problem = $this->upload("sku,title\nA,B\n");

        self::assertSame(422, $this->responseStatus());
        self::assertSame(['header.title', 'header.name'], array_column($problem['violations'], 'path'));
    }

    public function testAViewerCannotImport(): void
    {
        $this->signInAs(Role::VIEWER);

        $this->upload("sku,name\n{$this->prefix}-1,Tee\n", dryRun: true);

        self::assertSame(403, $this->responseStatus());
    }

    /** @return array<mixed> */
    private function upload(string $csv, bool $dryRun = false): array
    {
        $this->client->request('POST', '/api/product-imports'.($dryRun ? '?dryRun=true' : ''), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'text/csv',
        ], content: $csv);

        return $this->json();
    }
}
