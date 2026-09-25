<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Application;

use Kanso\Core\Internal\Application\Catalog\ProductImporter;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Tests\Support\InMemoryProducts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Product CSV import: matched by SKU, idempotent, bad rows reported and skipped. */
#[CoversClass(ProductImporter::class)]
final class ProductImporterTest extends TestCase
{
    private InMemoryProducts $products;
    private ProductImporter $importer;
    private int $transactions = 0;

    protected function setUp(): void
    {
        $this->products = new InMemoryProducts();
        $transaction = new class($this->transactions) implements TransactionInterface {
            public function __construct(private int &$count)
            {
            }

            public function run(callable $work): mixed
            {
                ++$this->count;

                return $work();
            }
        };
        $this->importer = new ProductImporter($this->products, $transaction, new MockClock('2026-09-25 10:00:00', 'UTC'));
    }

    public function testNewSkusAreCreatedAndKnownOnesUpdated(): void
    {
        $this->products->add(new Product('TEE-1', 'Old name', '111', 100, new \DateTimeImmutable()));

        $result = $this->importer->import("sku,name,barcode,weightGrams\nTEE-1,Tee,7350000000001,180\nMUG-1,Mug,,350\n", false);

        self::assertSame([2, 1, 1, 0, 0], [$result->rows, $result->created, $result->updated, $result->unchanged, $result->failed]);
        self::assertSame(1, $this->transactions, 'One transaction for the whole file.');
        $tee = $this->products->findBySku('TEE-1');
        self::assertSame(['Tee', '7350000000001', 180], [$tee?->name(), $tee?->barcode(), $tee?->weightGrams()]);
        $mug = $this->products->findBySku('MUG-1');
        self::assertSame(['Mug', null, 350], [$mug?->name(), $mug?->barcode(), $mug?->weightGrams()]);
    }

    public function testTheSameFileTwiceChangesNothingTheSecondTime(): void
    {
        $csv = "sku;name;barcode\nTEE-1;Tee;123\nMUG-1;Mug;\n";
        $this->importer->import($csv, false);

        $again = $this->importer->import($csv, false);

        self::assertSame([0, 0, 2], [$again->created, $again->updated, $again->unchanged]);
        self::assertSame(1, $this->transactions, 'Nothing to write, so no transaction.');
    }

    public function testADryRunCountsButWritesNothing(): void
    {
        $result = $this->importer->import("sku,name\nTEE-1,Tee\n", true);

        self::assertTrue($result->dryRun);
        self::assertSame(1, $result->created);
        self::assertSame([], $this->products->items);
        self::assertSame(0, $this->transactions);
    }

    public function testALeftOutColumnKeepsTheValueAndAnEmptyCellClearsIt(): void
    {
        $this->products->add(new Product('TEE-1', 'Tee', '123', 180, new \DateTimeImmutable()));

        $this->importer->import("sku,name,barcode\nTEE-1,Tee,\n", false);

        $tee = $this->products->findBySku('TEE-1');
        self::assertNull($tee?->barcode(), 'An empty cell clears the barcode.');
        self::assertSame(180, $tee?->weightGrams(), 'No weightGrams column: the weight stays.');
    }

    public function testBadRowsAreReportedWithTheirRowNumberAndTheRestImported(): void
    {
        $csv = "sku,name,barcode,weightGrams\n"
            ."GOOD-1,Good,,\n"       // row 2
            ."\n"                    // row 3: blank, skipped but counted
            ."has space,,x y,1.5\n"  // row 4
            ."GOOD-1,Again,,\n"      // row 5: duplicate of row 2
            ."GOOD-2,Good,,,extra\n"; // row 6: a value outside the columns

        $result = $this->importer->import($csv, false);

        self::assertSame([4, 1, 3], [$result->rows, $result->created, $result->failed]);
        self::assertSame(
            [[4, 'weightGrams', 'integer'], [4, 'sku', 'format'], [4, 'name', 'required'], [4, 'barcode', 'format'], [5, 'sku', 'duplicate'], [6, 'row', 'stray_cell']],
            array_map(static fn (array $error): array => [$error['row'], $error['field'], $error['code']], $result->errors),
        );
        self::assertNotNull($this->products->findBySku('GOOD-1'));
        self::assertNull($this->products->findBySku('GOOD-2'));
    }

    public function testSpellingsMySqlTreatsAsOneSkuAreDuplicatesInAFile(): void
    {
        // Case and accents are ignored by the SKU's collation, so these two would be one product.
        $result = $this->importer->import("sku,name\nTomdosa-Lös,One\ntomdosa-los,Two\n", true);

        self::assertSame(1, $result->created);
        self::assertSame([[3, 'sku', 'duplicate']], array_map(static fn (array $error): array => [$error['row'], $error['field'], $error['code']], $result->errors));
    }

    public function testExcelOutputIsRead(): void
    {
        // A byte-order mark, semicolons, CRLF, quoted cells and padding columns.
        $result = $this->importer->import("\u{FEFF}SKU;Name;Weight grams;;\r\nTEE-1;\"Tee; black\";180;;\r\n", false);

        self::assertSame(1, $result->created);
        self::assertSame(['Tee; black', 180], [$this->products->findBySku('TEE-1')?->name(), $this->products->findBySku('TEE-1')?->weightGrams()]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function badFiles(): iterable
    {
        yield 'empty' => ['', 'file', 'empty'];
        yield 'header only' => ["sku,name\n", 'file', 'no_rows'];
        yield 'not UTF-8' => ["sku,name\nA,\xE5ngest\n", 'file', 'encoding'];
        yield 'unknown column' => ["sku,name,colour\nA,B,red\n", 'header.colour', 'unknown_column'];
        yield 'missing column' => ["sku,barcode\nA,1\n", 'header.name', 'missing_column'];
        yield 'duplicate column' => ["sku,name,Name\nA,B,C\n", 'header.Name', 'duplicate_column'];
        yield 'too many rows' => ["sku,name\n".str_repeat("A,B\n", ProductImporter::MAX_ROWS + 1), 'file', 'too_many_rows'];
    }

    #[DataProvider('badFiles')]
    public function testAFileThatCannotBeReadImportsNothing(string $csv, string $path, string $code): void
    {
        try {
            $this->importer->import($csv, false);
            self::fail('Expected a validation failure.');
        } catch (ValidationFailed $e) {
            self::assertSame([$path, $code], [$e->violations()[0]['path'], $e->violations()[0]['code']]);
        }
        self::assertSame([], $this->products->items);
    }

    public function testExactlyTheRowLimitIsAllowed(): void
    {
        $csv = "sku,name\n";
        for ($i = 1; $i <= ProductImporter::MAX_ROWS; ++$i) {
            $csv .= "SKU-$i,Product $i\n";
        }

        self::assertSame(ProductImporter::MAX_ROWS, $this->importer->import($csv, true)->created);
    }
}
