<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Document;

/**
 * The words printed on documents, in the languages the UI has (CLAUDE.md:
 * Swedish and English from day one). A packing slip is read by the buyer, so
 * it is written in the language asked for, not the operator's.
 */
final class DocumentLabels
{
    private const array LABELS = [
        'en' => [
            'pick_list' => 'Pick list',
            'packing_slip' => 'Packing slip',
            'order' => 'Order',
            'placed' => 'Placed',
            'channel' => 'Channel',
            'customer' => 'Customer',
            'ship_to' => 'Ship to',
            'bill_to' => 'Bill to',
            'position' => '#',
            'sku' => 'SKU',
            'product' => 'Product',
            'quantity' => 'Qty',
            'picked' => 'Picked',
            'total_units' => 'Total units',
            'lines' => 'Lines',
            'picked_by' => 'Picked by',
            'checked_by' => 'Checked by',
            'thanks' => 'Thank you for your order.',
            'generated' => 'Generated',
        ],
        'sv' => [
            'pick_list' => 'Plocklista',
            'packing_slip' => 'Följesedel',
            'order' => 'Order',
            'placed' => 'Lagd',
            'channel' => 'Kanal',
            'customer' => 'Kund',
            'ship_to' => 'Leveransadress',
            'bill_to' => 'Fakturaadress',
            'position' => '#',
            'sku' => 'Artikelnr',
            'product' => 'Produkt',
            'quantity' => 'Antal',
            'picked' => 'Plockad',
            'total_units' => 'Totalt antal',
            'lines' => 'Rader',
            'picked_by' => 'Plockad av',
            'checked_by' => 'Kontrollerad av',
            'thanks' => 'Tack för din beställning.',
            'generated' => 'Skapad',
        ],
    ];

    private function __construct()
    {
    }

    /** @return array<string, string> */
    public static function for(string $locale): array
    {
        return self::LABELS[$locale] ?? self::LABELS['en'];
    }

    /** @return list<string> */
    public static function locales(): array
    {
        return array_keys(self::LABELS);
    }
}
