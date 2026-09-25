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
use Kanso\Core\Internal\Domain\Order\OrderLine;
use Kanso\Core\Internal\Domain\Order\Transition;
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

    public function testAPackingSlipForOneShipmentListsWhatIsInThatParcel(): void
    {
        $order = $this->order();
        $order->apply(Transition::Confirm, new Actor('u1', 'Olle'), new \DateTimeImmutable('2026-09-25 13:00:00'));
        array_map(static fn (OrderLine $line) => $line->markReserved(), $order->lines());
        [$tee, $socks] = $order->lines();
        $order->ship([['line' => $socks, 'quantity' => 2]], null, null, new \DateTimeImmutable('2026-09-25 14:00:00'), new Actor('u1', 'Olle'), new \DateTimeImmutable('2026-09-25 14:00:00'));
        $second = $order->ship([['line' => $tee, 'quantity' => 1]], 'PostNord', '00370712345', new \DateTimeImmutable('2026-09-26 09:00:00'), new Actor('u1', 'Olle'), new \DateTimeImmutable('2026-09-26 09:00:00'));

        $html = new TwigTemplateRenderer(self::TEMPLATES)->render(DocumentType::PackingSlip, OrderDocumentData::build($order, 'sv', new \DateTimeImmutable('2026-09-26 10:00:00'), $second));

        self::assertStringContainsString('<dt>Paket</dt>', $html);
        self::assertStringContainsString('<strong>2</strong>', $html, 'the second parcel');
        self::assertStringContainsString('PostNord 00370712345', $html);
        self::assertStringContainsString('<td class="sku">TSHIRT-M</td>', $html);
        self::assertStringNotContainsString('<td class="sku">SOCKS</td>', $html, 'the socks went in the first parcel');
        self::assertMatchesRegularExpression('#<td class="num">1</td>#', $html, 'one unit of three');
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

    public function testSeveralOrdersEachStartOnANewPageAndSayWhoseTheyAre(): void
    {
        $now = new \DateTimeImmutable('2026-09-26 10:00:00');
        $renderer = new TwigTemplateRenderer(self::TEMPLATES);
        $data = static fn (string $locale, Order ...$orders): array => [
            'locale' => $locale,
            'labels' => DocumentLabels::for($locale),
            'orders' => array_map(static fn (Order $order): array => OrderDocumentData::build($order, $locale, $now), $orders),
            'generatedAt' => '26 sep. 2026 10:00 UTC',
        ];

        $html = $renderer->render(DocumentType::PickList, $data('sv', $this->order(), $this->order(number: '10043')), batch: true);

        self::assertSame(2, substr_count($html, '<h1>Plocklista</h1>'));
        self::assertStringContainsString('<strong>10042</strong>', $html);
        self::assertStringContainsString('<strong>10043</strong>', $html);
        self::assertSame(1, substr_count($html, 'page-break-before: always'), 'the second order starts a page; the first does not');
        self::assertStringContainsString('<th colspan="5" class="continued">Order 10043</th>', $html, 'repeated on a long order\'s next page');
        self::assertStringContainsString('Plocklistor · 2 ordrar · Skapad', $html);
        self::assertStringContainsString('<title>Plocklistor</title>', $html);

        $slips = $renderer->render(DocumentType::PackingSlip, $data('en', $this->order()), batch: true);
        self::assertStringContainsString('<h1>Packing slip</h1>', $slips);
        self::assertStringContainsString('Packing slips · 1 order ·', $slips);
        self::assertStringNotContainsString('199', $slips, 'no unit price');

        $pdf = new DompdfRenderer(self::TEMPLATES)->render($html);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, preg_match_all('#/Type /Page\b#', $pdf), 'one page per order');
    }

    public function testOneOrdersDocumentIsUnchangedByTheBatchLayout(): void
    {
        $html = $this->render(DocumentType::PickList, 'en', $this->order());

        self::assertStringNotContainsString('class="continued"', $html);
        self::assertStringContainsString('<title>Pick list 10042</title>', $html);
        self::assertStringContainsString('Order 10042 · Generated', $html);
    }

    private function render(DocumentType $type, string $locale, Order $order): string
    {
        return new TwigTemplateRenderer(self::TEMPLATES)->render($type, OrderDocumentData::build($order, $locale, new \DateTimeImmutable('2026-09-26 10:00:00')));
    }

    /** @param array<string, string|null>|null $billing */
    private function order(?array $billing = null, string $customerName = 'Åsa Öberg', string $number = '10042'): Order
    {
        $now = new \DateTimeImmutable('2026-09-25 12:00:00');
        $shipping = ['name' => null, 'line1' => 'Storgatan 1', 'line2' => null, 'postalCode' => '111 22', 'city' => 'Stockholm', 'region' => null, 'countryCode' => 'SE', 'phone' => null];

        return Order::place(
            $number,
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
