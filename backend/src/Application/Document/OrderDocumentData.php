<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Document;

use Kanso\Core\Internal\Domain\Order\Order;

/**
 * What a document template sees of an order: plain strings and numbers,
 * already formatted for the document's language. Templates format nothing
 * themselves, so a template cannot get a date or a country wrong.
 */
final class OrderDocumentData
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function build(Order $order, string $locale, \DateTimeImmutable $now): array
    {
        // Timestamps are stored in UTC; until an installation has a time zone
        // setting, documents print UTC too, and say so.
        $date = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC');
        $dateTime = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, 'UTC');

        $lines = [];
        $units = 0;
        foreach ($order->lines() as $line) {
            $lines[] = [
                'position' => $line->position(),
                'sku' => $line->skuCode(),
                'name' => $line->name(),
                'quantity' => $line->quantity(),
            ];
            $units += $line->quantity();
        }

        $billing = $order->billingAddress();

        return [
            'locale' => $locale,
            'labels' => DocumentLabels::for($locale),
            'order' => [
                'number' => $order->number(),
                'placedAt' => (string) $date->format($order->placedAt()),
                'channel' => $order->channel()->name(),
                'customerName' => $order->customerName(),
                'customerEmail' => $order->customerEmail(),
            ],
            'shipTo' => self::addressLines($order->shippingAddress(), $order->customerName(), $locale),
            // Printed only when it differs: most orders bill where they ship.
            'billTo' => null === $billing || $billing === $order->shippingAddress() ? null : self::addressLines($billing, $order->customerName(), $locale),
            'lines' => $lines,
            'totalUnits' => $units,
            'generatedAt' => $dateTime->format($now).' UTC',
        ];
    }

    /**
     * An address as the lines printed on a label.
     *
     * @param array<string, string|null> $address
     *
     * @return list<string>
     */
    private static function addressLines(array $address, string $fallbackName, string $locale): array
    {
        $country = $address['countryCode'] ?? null;
        $countryName = null === $country || '' === $country ? null : (\Locale::getDisplayRegion('-'.$country, $locale) ?: $country);
        $lines = [
            $address['name'] ?? $fallbackName,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            trim(($address['postalCode'] ?? '').' '.($address['city'] ?? '')),
            $address['region'] ?? null,
            $countryName,
            $address['phone'] ?? null,
        ];

        return array_values(array_filter($lines, static fn (?string $line): bool => null !== $line && '' !== $line));
    }
}
