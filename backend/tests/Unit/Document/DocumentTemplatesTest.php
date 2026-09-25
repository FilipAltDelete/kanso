<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Document;

use Kanso\Core\Internal\Application\Document\DocumentLabels;
use Kanso\Core\Internal\Application\Document\OrderDocumentData;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Document\DocumentType;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\NewOrderLine;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderCustomer;
use Kanso\Core\Internal\Infrastructure\Document\DompdfRenderer;
use Kanso\Core\Internal\Infrastructure\Document\TwigTemplateRenderer;
use PHPUnit\Framework\TestCase;

/** The templates themselves: what a document says, in which language, and that it becomes a PDF. */
final class DocumentTemplatesTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../../templates/documents';

    public function testAPickListInSwedish(): void
    {
        $html = $this->render(DocumentType::PickList, 'sv', $this->order());

        self::assertStringContainsString('<h1>Plocklista</h1>', $html);
        self::assertStringContainsString('<strong>10042</strong>', $html);
        self::assertStringContainsString('<dt>Plocka från</dt>', $html);
        self::assertStringContainsString('<strong>WH1 · Main</strong>', $html);
        self::assertStringContainsString('Åsa Öberg<br>Storgatan 1<br>111 22 Stockholm<br>Sverige<br>', $html);
        self::assertStringContainsString('<td class="sku">TSHIRT-M</td>', $html);
        self::assertStringContainsString('Totalt antal (2 rader)', $html);
        self::assertMatchesRegularExpression('#<td class="num">5</td>#', $html, 'total units');
        self::assertStringContainsString('Plockad av', $html);
    }

    public function testAPackingSlipInEnglishHasNoPricesAndABillingAddressOnlyWhenItDiffers(): void
    {
        $html = $this->render(DocumentType::PackingSlip, 'en', $this->order());
        self::assertStringContainsString('<h1>Packing slip</h1>', $html);
        self::assertStringContainsString('Sweden', $html);
        self::assertStringNotContainsString('Bill to', $html);
        self::assertStringNotContainsString('199', $html, 'no unit price');

        $html = $this->render(DocumentType::PackingSlip, 'en', $this->order(['line1' => 'Box 7', 'postalCode' => '0150', 'city' => 'Oslo', 'countryCode' => 'NO']));
        self::assertStringContainsString('Bill to', $html);
        self::assertStringContainsString('Box 7<br>0150 Oslo<br>Norway<br>', $html);
    }

    public function testWhatCustomersTypeIsEscaped(): void
    {
        $html = $this->render(DocumentType::PickList, 'en', $this->order(customerName: '<script>alert(1)</script>'));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEveryLanguageHasEveryLabel(): void
    {
        $keys = array_keys(DocumentLabels::for('en'));
        foreach (DocumentLabels::locales() as $locale) {
            self::assertSame($keys, array_keys(DocumentLabels::for($locale)), $locale);
        }
    }

    public function testTheHtmlBecomesAPdf(): void
    {
        $pdf = new DompdfRenderer(self::TEMPLATES)->render($this->render(DocumentType::PickList, 'sv', $this->order()));

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Type /Page', $pdf);
    }

    private function render(DocumentType $type, string $locale, Order $order): string
    {
        return new TwigTemplateRenderer(self::TEMPLATES)->render($type, OrderDocumentData::build($order, $locale, new \DateTimeImmutable('2026-09-26 10:00:00')));
    }

    /** @param array<string, string|null>|null $billing */
    private function order(?array $billing = null, string $customerName = 'Åsa Öberg'): Order
    {
        $now = new \DateTimeImmutable('2026-09-25 12:00:00');
        $shipping = ['name' => null, 'line1' => 'Storgatan 1', 'line2' => null, 'postalCode' => '111 22', 'city' => 'Stockholm', 'region' => null, 'countryCode' => 'SE', 'phone' => null];

        return Order::place(
            '10042',
            new Channel('manual', 'Manual', 'manual', 'SEK', $now),
            'SEK',
            new Location('WH1', 'Main', new Address(), $now),
            new OrderCustomer(null, $customerName, 'asa@example.com', $shipping, $billing),
            [
                new NewOrderLine(new Product('TSHIRT-M', 'T-shirt, M', null, null, $now), 'T-shirt, M', 3, 19_950),
                new NewOrderLine(new Product('SOCKS', 'Strumpor', null, null, $now), 'Strumpor', 2, 4_900),
            ],
            $now,
            new Actor('u1', 'Olle'),
            $now,
        );
    }
}
