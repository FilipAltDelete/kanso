<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Import\Collation;
use Kanso\Core\Internal\Application\Import\CsvFile;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\ChannelStoreInterface;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderStoreInterface;
use Kanso\Core\Internal\Domain\Order\OrderTag;
use Kanso\Core\Internal\Domain\Order\PaymentStatus;

/**
 * Creates orders from a CSV file with one row per order line (ADR-0008,
 * following ADR-0006). Rows with the same `orderReference` (and channel)
 * are one order; the order's own columns — customer, address, currency and
 * so on — are read from the first row that fills them, and a later row that
 * says something different is an error.
 *
 * The reference is stored on the order as its external reference, unique
 * per channel. An order whose reference the channel already has is left
 * alone, so importing the same file twice creates nothing the second time.
 *
 * Every order goes through OrderService, so an imported order is checked
 * exactly like one entered by hand (unknown SKUs included) and is written in
 * its own transaction. An order with any problem is skipped whole — never
 * created with some of its lines — and reported at the rows it came from.
 * A dry run checks everything and writes nothing: the preview.
 *
 * `paymentStatus`, `tags` (separated by "|") and `note` are order columns
 * too. They are set on the new order in the transaction that creates it,
 * through the order's own methods, so each writes its usual event.
 */
final class OrderImporter
{
    /** Field => the column that holds it. */
    public const array COLUMNS = [
        'orderReference' => 'orderReference',
        'channel' => 'channel',
        'placedAt' => 'placedAt',
        'currency' => 'currency',
        'location' => 'location',
        'customerName' => 'customerName',
        'customerEmail' => 'customerEmail',
        'shippingName' => 'shippingName',
        'shippingLine1' => 'shippingLine1',
        'shippingLine2' => 'shippingLine2',
        'shippingPostalCode' => 'shippingPostalCode',
        'shippingCity' => 'shippingCity',
        'shippingRegion' => 'shippingRegion',
        'shippingCountry' => 'shippingCountry',
        'shippingPhone' => 'shippingPhone',
        'sku' => 'sku',
        'lineName' => 'lineName',
        'quantity' => 'quantity',
        'unitPrice' => 'unitPrice',
        'paymentStatus' => 'paymentStatus',
        'tags' => 'tags',
        'note' => 'note',
    ];
    /** Separates the tags in the `tags` column; not a comma, which the list filter uses. */
    public const string TAG_SEPARATOR = '|';
    private const array REQUIRED = ['orderReference', 'customerName', 'shippingLine1', 'shippingPostalCode', 'shippingCity', 'shippingCountry', 'sku', 'quantity', 'unitPrice'];
    /** The columns that belong to a line; every other column belongs to the order. */
    private const array LINE_FIELDS = ['sku', 'lineName', 'quantity', 'unitPrice'];

    /** Where OrderService's violation paths come from in the file. */
    private const array ORDER_PATHS = [
        'externalReference' => 'orderReference',
        'channel' => 'channel',
        'placedAt' => 'placedAt',
        'currency' => 'currency',
        'location' => 'location',
        'customer.name' => 'customerName',
        'customer.email' => 'customerEmail',
        'shippingAddress.name' => 'shippingName',
        'shippingAddress.line1' => 'shippingLine1',
        'shippingAddress.line2' => 'shippingLine2',
        'shippingAddress.postalCode' => 'shippingPostalCode',
        'shippingAddress.city' => 'shippingCity',
        'shippingAddress.region' => 'shippingRegion',
        'shippingAddress.countryCode' => 'shippingCountry',
        'shippingAddress.phone' => 'shippingPhone',
    ];
    private const array LINE_PATHS = ['sku' => 'sku', 'name' => 'lineName', 'quantity' => 'quantity', 'unitPrice' => 'unitPrice'];

    public function __construct(
        private readonly OrderService $service,
        private readonly OrderStoreInterface $orders,
        private readonly ChannelStoreInterface $channels,
    ) {
    }

    public function import(string $csv, bool $dryRun, Actor $actor): OrderImportResult
    {
        $file = CsvFile::read($csv, self::COLUMNS, self::REQUIRED);
        $errors = [];

        // Rows into orders, in the order the file has them.
        /** @var array<string, array{reference: string, channel: string, rows: array<int, array<string, string>>}> $groups */
        $groups = [];
        foreach (array_keys($file->rows) as $row) {
            $values = $file->values($row);
            if (null === $values) {
                [$field, $code, $message] = CsvFile::strayCell();
                $errors[] = self::error($row, null, $field, $code, $message);
                continue;
            }
            $reference = $values['orderReference'];
            if ('' === $reference || mb_strlen($reference) > 64) {
                $errors[] = self::error($row, '' === $reference ? null : $reference, 'orderReference', 'required', 'Every row needs the order reference it belongs to, at most 64 characters.');
                continue;
            }
            $channel = '' === ($values['channel'] ?? '') ? Channel::MANUAL : $values['channel'];
            $key = Collation::key($channel)."\0".Collation::key($reference);
            $groups[$key] ??= ['reference' => $reference, 'channel' => $channel, 'rows' => []];
            $groups[$key]['rows'][$row] = $values;
        }

        $existing = $this->existing($groups);
        $created = $already = $failed = 0;

        foreach ($groups as $key => $group) {
            if (isset($existing[$key])) {
                ++$already;
                continue;
            }

            $groupErrors = [];
            $input = $this->input($group, $groupErrors);
            $annotations = self::annotations($group, $groupErrors);
            if ([] === $groupErrors) {
                try {
                    if ($dryRun) {
                        $this->service->check($input);
                    } else {
                        $this->service->create($input, $actor, self::annotate($annotations, $actor));
                    }
                    ++$created;
                    continue;
                } catch (ValidationFailed $e) {
                    foreach ($e->violations() as $violation) {
                        [$row, $field] = self::locate($group, $violation['path']);
                        $groupErrors[] = self::error($row, $group['reference'], $field, $violation['code'], $violation['message']);
                    }
                }
            }

            ++$failed;
            array_push($errors, ...$groupErrors);
        }

        usort($errors, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return new OrderImportResult($dryRun, \count($file->rows), \count($groups), $created, $already, $failed, $errors);
    }

    /**
     * The groups whose channel already has an order under their reference.
     *
     * @param array<string, array{reference: string, channel: string, rows: array<int, array<string, string>>}> $groups
     *
     * @return array<string, true>
     */
    private function existing(array $groups): array
    {
        $byChannel = [];
        foreach ($groups as $group) {
            $byChannel[$group['channel']][] = $group['reference'];
        }

        $existing = [];
        foreach ($byChannel as $code => $references) {
            // An unknown channel has no orders; OrderService reports it per order.
            $channel = $this->channels->findByCode((string) $code);
            if (null === $channel) {
                continue;
            }
            foreach ($this->orders->findByExternalReferences($channel, $references) as $order) {
                $existing[Collation::key((string) $code)."\0".Collation::key((string) $order->externalReference())] = true;
            }
        }

        return $existing;
    }

    /**
     * One order as OrderService's create input. Problems only a CSV can have
     * (a number that is not one, rows that disagree) are added to `$errors`.
     *
     * @param array{reference: string, channel: string, rows: array<int, array<string, string>>}      $group
     * @param list<array{row: int, reference: ?string, field: string, code: string, message: string}> $errors
     *
     * @return array<string, mixed>
     */
    private function input(array $group, array &$errors): array
    {
        $order = [];
        $source = [];
        foreach ($group['rows'] as $row => $values) {
            foreach ($values as $field => $value) {
                if (\in_array($field, self::LINE_FIELDS, true) || '' === $value) {
                    continue;
                }
                if (!isset($order[$field])) {
                    $order[$field] = $value;
                    $source[$field] = $row;
                } elseif ($order[$field] !== $value) {
                    $errors[] = self::error($row, $group['reference'], $field, 'inconsistent', \sprintf('Row %d of this order says "%s"; every row of an order must agree.', $source[$field], $order[$field]));
                }
            }
        }

        $currency = strtoupper($order['currency'] ?? $this->channels->findByCode($group['channel'])?->currency() ?? '');
        $digits = self::fractionDigits($currency);

        $lines = [];
        foreach ($group['rows'] as $row => $values) {
            $quantity = $values['quantity'];
            if (1 !== preg_match('/^\d{1,7}$/', $quantity)) {
                $errors[] = self::error($row, $group['reference'], 'quantity', 'integer', 'The quantity is a whole number, such as 2.');
            }
            $unitPrice = self::minorUnits($values['unitPrice'], $digits);
            if (null === $unitPrice) {
                $errors[] = self::error($row, $group['reference'], 'unitPrice', 'price', \sprintf('The unit price is a number with at most %d decimals, such as 199.00, without currency or thousands separators.', $digits));
            }
            $lines[] = array_filter([
                'sku' => $values['sku'],
                'name' => '' === ($values['lineName'] ?? '') ? null : $values['lineName'],
                'quantity' => (int) $quantity,
                'unitPrice' => $unitPrice ?? 0,
            ], static fn (mixed $value): bool => null !== $value);
        }

        $address = static fn (string $field): ?string => $order[$field] ?? null;

        return array_filter([
            'channel' => $group['channel'],
            'externalReference' => $group['reference'],
            'currency' => '' === $currency ? null : $currency,
            'placedAt' => $order['placedAt'] ?? null,
            'location' => $order['location'] ?? null,
            'customer' => array_filter(['name' => $order['customerName'] ?? null, 'email' => $order['customerEmail'] ?? null], static fn (?string $value): bool => null !== $value),
            'shippingAddress' => array_filter([
                'name' => $address('shippingName'),
                'line1' => $address('shippingLine1'),
                'line2' => $address('shippingLine2'),
                'postalCode' => $address('shippingPostalCode'),
                'city' => $address('shippingCity'),
                'region' => $address('shippingRegion'),
                'countryCode' => null === $address('shippingCountry') ? null : strtoupper((string) $address('shippingCountry')),
                'phone' => $address('shippingPhone'),
            ], static fn (?string $value): bool => null !== $value),
            'lines' => $lines,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * The payment status, tags and note the file gives the order, checked.
     * Rows that disagree are already reported by input(); these come from the
     * first row that fills them.
     *
     * @param array{reference: string, channel: string, rows: array<int, array<string, string>>}      $group
     * @param list<array{row: int, reference: ?string, field: string, code: string, message: string}> $errors
     *
     * @return array{paymentStatus: ?PaymentStatus, tags: list<string>, note: ?string}
     */
    private static function annotations(array $group, array &$errors): array
    {
        $annotations = ['paymentStatus' => null, 'tags' => [], 'note' => null];
        $reference = $group['reference'];

        $first = self::first($group, 'paymentStatus');
        if (null !== $first) {
            [$row, $value] = $first;
            $annotations['paymentStatus'] = PaymentStatus::tryFrom(strtolower(str_replace([' ', '-'], '_', $value)));
            if (null === $annotations['paymentStatus']) {
                $errors[] = self::error($row, $reference, 'paymentStatus', 'unknown_payment_status', \sprintf('Unknown payment status "%s"; one of: %s.', $value, implode(', ', PaymentStatus::values())));
            }
        }

        $first = self::first($group, 'tags');
        if (null !== $first) {
            [$row, $value] = $first;
            $tags = [];
            foreach (explode(self::TAG_SEPARATOR, $value) as $name) {
                if ('' === trim($name)) {
                    continue; // "a||b" or a trailing separator
                }
                try {
                    $name = OrderTag::normalize($name);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = self::error($row, $reference, 'tags', 'tag', $e->getMessage().\sprintf(' Separate tags with "%s".', self::TAG_SEPARATOR));
                    continue;
                }
                foreach ($tags as $tag) {
                    if (OrderTag::same($tag, $name)) {
                        continue 2;
                    }
                }
                $tags[] = $name;
            }
            if (\count($tags) > Order::MAX_TAGS) {
                $errors[] = self::error($row, $reference, 'tags', 'too_many_tags', \sprintf('An order has at most %d tags; this row gives %d.', Order::MAX_TAGS, \count($tags)));
            }
            $annotations['tags'] = $tags;
        }

        $first = self::first($group, 'note');
        if (null !== $first) {
            [$row, $value] = $first;
            if (mb_strlen($value) > Order::MAX_NOTE_LENGTH) {
                $errors[] = self::error($row, $reference, 'note', 'too_long', \sprintf('A note has at most %d characters.', Order::MAX_NOTE_LENGTH));
            }
            $annotations['note'] = $value;
        }

        return $annotations;
    }

    /**
     * What create() runs on the new order in its transaction, or null when
     * the file gives nothing to set. Unpaid, the default, writes no event.
     *
     * @param array{paymentStatus: ?PaymentStatus, tags: list<string>, note: ?string} $annotations
     *
     * @return (\Closure(Order, \DateTimeImmutable): void)|null
     */
    private static function annotate(array $annotations, Actor $actor): ?\Closure
    {
        if (null === $annotations['paymentStatus'] && [] === $annotations['tags'] && null === $annotations['note']) {
            return null;
        }

        return static function (Order $order, \DateTimeImmutable $now) use ($annotations, $actor): void {
            if (null !== $annotations['paymentStatus']) {
                $order->changePaymentStatus($annotations['paymentStatus'], $actor, $now);
            }
            if ([] !== $annotations['tags']) {
                $order->changeTags($annotations['tags'], [], $actor, $now);
            }
            if (null !== $annotations['note']) {
                $order->addNote($annotations['note'], $actor, $now);
            }
        };
    }

    /**
     * The first row of the order that fills a column, and its value.
     *
     * @param array{reference: string, channel: string, rows: array<int, array<string, string>>} $group
     *
     * @return array{int, string}|null
     */
    private static function first(array $group, string $field): ?array
    {
        foreach ($group['rows'] as $row => $values) {
            if ('' !== ($values[$field] ?? '')) {
                return [$row, $values[$field]];
            }
        }

        return null;
    }

    /**
     * The row and column an OrderService violation is about: a line's to
     * its row, the order's to the first row of the order.
     *
     * @param array{reference: string, channel: string, rows: array<int, array<string, string>>} $group
     *
     * @return array{int, string}
     */
    private static function locate(array $group, string $path): array
    {
        $rows = array_keys($group['rows']);
        if (1 === preg_match('/^lines\[(\d+)\](?:\.(\w+))?$/', $path, $match)) {
            return [$rows[(int) $match[1]] ?? $rows[0], self::LINE_PATHS[$match[2] ?? ''] ?? 'row'];
        }

        return [$rows[0], self::ORDER_PATHS[$path] ?? ('lines' === $path ? 'sku' : 'row')];
    }

    /**
     * "199", "199.5" or "199,50" in major units as minor units, or null when
     * it is not such a number.
     */
    private static function minorUnits(string $value, int $digits): ?int
    {
        $pattern = 0 === $digits ? '/^(\d{1,13})$/' : \sprintf('/^(\d{1,13})(?:[.,](\d{1,%d}))?$/', $digits);
        if (1 !== preg_match($pattern, $value, $match)) {
            return null;
        }

        return (int) $match[1] * 10 ** $digits + (int) str_pad($match[2] ?? '', $digits, '0');
    }

    /** SEK 2, JPY 0, KWD 3; 2 for a code intl does not know, which OrderService then refuses. */
    private static function fractionDigits(string $currency): int
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            return 2;
        }
        // Not `new X()->method()`: valid PHP 8.4, but deptrac's parser cannot read it and skips the file.
        $formatter = new \NumberFormatter('en@currency='.$currency, \NumberFormatter::CURRENCY);
        $digits = $formatter->getAttribute(\NumberFormatter::FRACTION_DIGITS);

        return \is_int($digits) && $digits >= 0 ? $digits : 2;
    }

    /** @return array{row: int, reference: ?string, field: string, code: string, message: string} */
    private static function error(int $row, ?string $reference, string $field, string $code, string $message): array
    {
        return ['row' => $row, 'reference' => $reference, 'field' => $field, 'code' => $code, 'message' => $message];
    }
}
