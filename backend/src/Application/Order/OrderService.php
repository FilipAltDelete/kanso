<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\NotFound;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Customer\CustomerStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\ChannelStoreInterface;
use Kanso\Core\Internal\Domain\Order\Money;
use Kanso\Core\Internal\Domain\Order\NewOrderLine;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderCustomer;
use Kanso\Core\Internal\Domain\Order\OrderLine;
use Kanso\Core\Internal\Domain\Order\OrderQuery;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\OrderStoreInterface;
use Kanso\Core\Internal\Domain\Order\PaymentStatus;
use Kanso\Core\Internal\Domain\Order\ShipmentRefused;
use Kanso\Core\Internal\Domain\Order\Transition;
use Kanso\Core\Internal\Domain\Order\TransitionNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Creating, reading and moving orders. Input arrives as decoded JSON or query
 * parameters and is checked here, so every door into orders (REST now, CSV
 * import and connectors later) gets the same rules and the same violations.
 */
final class OrderService
{
    public const int MAX_LINES = 500;
    public const int MAX_QUANTITY = 1_000_000;
    /** 100 billion in major units: far above any real line, far below an overflow. */
    public const int MAX_UNIT_PRICE = 10_000_000_000_000;
    public const int MAX_SEARCH = 100;

    public function __construct(
        private readonly OrderStoreInterface $orders,
        private readonly ChannelStoreInterface $channels,
        private readonly CustomerStoreInterface $customers,
        private readonly ProductStoreInterface $products,
        private readonly LocationStoreInterface $locations,
        private readonly DefaultLocation $defaultLocation,
        private readonly OrderStock $stock,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input the create request body
     */
    public function create(array $input, Actor $actor): Order
    {
        $draft = $this->draft($input);

        $now = $this->clock->now();
        $order = Order::place(
            $this->orders->nextNumber(),
            $draft['channel'],
            $draft['currency'],
            $draft['location'],
            $draft['customer'],
            $draft['lines'],
            $draft['placedAt'] ?? $now,
            $actor,
            $now,
            $draft['externalReference'],
        );

        try {
            $this->transaction->run(fn () => $this->orders->add($order));
        } catch (ConcurrentModification $e) {
            // The unique key on (channel, external reference): created by
            // someone else between the check in draft() and this write.
            if (null === $draft['externalReference']) {
                throw $e;
            }
            throw self::referenceTaken($draft['externalReference']);
        }

        return $order;
    }

    /**
     * Everything create() checks, without writing anything or taking an
     * order number: an import's preview (ADR-0008). Throws the same
     * ValidationFailed that create() would.
     *
     * @param array<string, mixed> $input the create request body
     */
    public function check(array $input): void
    {
        $this->draft($input);
    }

    /**
     * The create input, checked. Every violation is collected before one
     * ValidationFailed is thrown.
     *
     * @param array<string, mixed> $input
     *
     * @return array{channel: Channel, externalReference: ?string, currency: string, placedAt: ?\DateTimeImmutable, location: Location, customer: OrderCustomer, lines: list<NewOrderLine>}
     */
    private function draft(array $input): array
    {
        $check = new OrderInput();

        $channelCode = $check->text($input['channel'] ?? Channel::MANUAL, 'channel', 64);
        $channel = null === $channelCode ? null : $this->channels->findByCode($channelCode);
        if (null !== $channelCode && null === $channel) {
            $check->violate('channel', \sprintf('No channel "%s".', $channelCode), 'unknown_channel');
        }

        // The order's number in the system it came from; one order per reference and channel.
        $reference = $check->text($input['externalReference'] ?? null, 'externalReference', 64, false);
        if (null !== $reference && null !== $channel && [] !== $this->orders->findByExternalReferences($channel, [$reference])) {
            $check->violate('externalReference', self::referenceTakenMessage($reference), 'taken');
        }

        $currency = $check->currency($input['currency'] ?? $channel?->currency(), 'currency');
        $placedAt = $check->instant($input['placedAt'] ?? null, 'placedAt');

        $customer = $input['customer'] ?? null;
        if (!\is_array($customer)) {
            $check->violate('customer', 'This value is required.', 'required');
            $customer = [];
        }
        $customerId = $check->uuid($customer['id'] ?? null, 'customer.id');
        // `customer.id` links the order to a customer record, which has to exist;
        // the name, email and addresses are still copied onto the order.
        if (null !== $customerId && null === $this->customers->findById($customerId)) {
            $check->violate('customer.id', \sprintf('No customer "%s".', $customerId), 'unknown_customer');
        }
        $customerName = $check->text($customer['name'] ?? null, 'customer.name', 255);
        $customerEmail = $check->email($customer['email'] ?? null, 'customer.email');
        $shipping = $check->address($input['shippingAddress'] ?? null, 'shippingAddress', true);
        $billing = $check->address($input['billingAddress'] ?? null, 'billingAddress', false);

        $lines = $this->lines($input['lines'] ?? null, $check);
        $location = $this->location($input['location'] ?? null, $check);

        if (null !== $currency) {
            try {
                $total = Money::zero($currency);
                foreach ($lines as $line) {
                    $total = $total->add(Money::of($line->unitPrice, $currency)->multiply($line->quantity));
                }
            } catch (\OverflowException) {
                // Each line is within range, but the sum of many is not.
                $check->violate('lines', 'The order total is too large.', 'out_of_range');
            }
        }

        $check->throwIfInvalid();
        \assert(null !== $channel && null !== $currency && null !== $customerName && null !== $shipping && null !== $location);

        return [
            'channel' => $channel,
            'externalReference' => $reference,
            'currency' => $currency,
            'placedAt' => $placedAt,
            'location' => $location,
            'customer' => new OrderCustomer($customerId, $customerName, $customerEmail, $shipping, $billing),
            'lines' => $lines,
        ];
    }

    private static function referenceTaken(string $reference): ValidationFailed
    {
        return new ValidationFailed([['path' => 'externalReference', 'message' => self::referenceTakenMessage($reference), 'code' => 'taken']]);
    }

    private static function referenceTakenMessage(string $reference): string
    {
        return \sprintf('This channel already has an order with the reference "%s".', $reference);
    }

    /** @return list<NewOrderLine> */
    private function lines(mixed $value, OrderInput $check): array
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            $check->violate('lines', 'An order needs at least one line.', 'required');

            return [];
        }
        if (\count($value) > self::MAX_LINES) {
            $check->violate('lines', \sprintf('An order can have at most %d lines.', self::MAX_LINES), 'too_many');

            return [];
        }

        $lines = [];
        foreach ($value as $index => $line) {
            $path = \sprintf('lines[%d]', $index);
            if (!\is_array($line)) {
                $check->violate($path, 'A line must be an object.', 'type');
                continue;
            }

            $sku = $check->text($line['sku'] ?? null, $path.'.sku', 64);
            // Optional: the product's own name unless the order says otherwise.
            $name = $check->text($line['name'] ?? null, $path.'.name', 255, false);
            $quantity = $check->integer($line['quantity'] ?? null, $path.'.quantity', 1, self::MAX_QUANTITY);
            $unitPrice = $check->integer($line['unitPrice'] ?? null, $path.'.unitPrice', 0, self::MAX_UNIT_PRICE);

            // Every line is for a product the catalogue has: that is what
            // stock is reserved against when the order is confirmed.
            $product = null === $sku ? null : $this->products->findBySku($sku);
            if (null !== $sku && null === $product) {
                $check->violate($path.'.sku', \sprintf('No product with SKU "%s".', $sku), 'unknown_sku');
            }

            if (null !== $product && null !== $quantity && null !== $unitPrice) {
                $lines[] = new NewOrderLine($product, $name ?? $product->name(), $quantity, $unitPrice);
            }
        }

        return $lines;
    }

    /** The location the order names by code, or the installation's default. */
    private function location(mixed $code, OrderInput $check): ?Location
    {
        $code = $check->text($code, 'location', 32, false);
        if (null !== $code) {
            $location = $this->locations->findByCode($code);
            if (null === $location) {
                $check->violate('location', \sprintf('No location "%s".', $code), 'unknown_location');
            }

            return $location;
        }

        try {
            return $this->defaultLocation->get();
        } catch (\DomainException $e) {
            $check->violate('location', $e->getMessage(), 'no_default_location');

            return null;
        }
    }

    public function get(string $id): Order
    {
        return $this->orders->findById($id) ?? throw new NotFound(\sprintf('No order "%s".', $id));
    }

    /**
     * Moves an order through the state machine. `$version` is the version
     * the caller last saw: a change made in between is a conflict to resolve
     * by reloading, never something to overwrite.
     */
    public function transition(string $id, mixed $transition, mixed $version, Actor $actor): Order
    {
        $check = new OrderInput();
        $name = $check->text($transition, 'transition', 32);
        $move = null === $name ? null : Transition::tryFrom($name);
        if (null !== $name && null === $move) {
            $check->violate('transition', \sprintf('Unknown transition "%s"; one of: %s.', $name, implode(', ', Transition::values())), 'unknown_transition');
        }
        $expected = $check->integer($version, 'version', 1, \PHP_INT_MAX);
        $check->throwIfInvalid();
        \assert(null !== $move && null !== $expected);

        if (Transition::Ship === $move) {
            // Shipping is shipments: which lines, how many, with which
            // tracking number. The order moves to shipped when the last goes.
            throw new Conflict('An order ships through its shipments: POST /api/orders/{id}/shipments.', [['path' => 'transition', 'message' => 'Record a shipment instead.', 'code' => 'use_shipments']]);
        }

        $order = $this->get($id);
        if ($order->version() !== $expected) {
            throw $this->stale($order);
        }

        try {
            // The status, its event and the stock it moves commit together or
            // not at all. The order's version check in the UPDATE fails if
            // someone saved the order meanwhile, and takes the stock with it.
            $this->transaction->run(fn () => $this->move($order, $move, $actor, $this->clock->now()));
        } catch (ConcurrentModification) {
            // Saved by someone else between our read and our write.
            throw $this->stale($order);
        }

        return $order;
    }

    private function move(Order $order, Transition $move, Actor $actor, \DateTimeImmutable $now): void
    {
        $heldStock = $order->holdsStock();

        if (Transition::Confirm === $move && null === $order->location()) {
            try {
                $order->assignLocation($this->defaultLocation->get());
            } catch (\DomainException $e) {
                throw new ValidationFailed([['path' => 'location', 'message' => $e->getMessage(), 'code' => 'no_default_location']]);
            }
        }

        try {
            $order->apply($move, $actor, $now);
        } catch (TransitionNotAllowed $e) {
            throw new Conflict($e->getMessage(), [['path' => 'transition', 'message' => $e->getMessage(), 'code' => 'transition_not_allowed']]);
        }

        if (!$heldStock && $order->holdsStock()) {
            $this->stock->reserve($order, $actor, $now);
        } elseif ($heldStock && !$order->holdsStock()) {
            $this->stock->release($order, $actor, $now);
        }
    }

    /**
     * Records a shipment: some lines, or part of a line. Its units come off
     * on hand and off the reservation, the lines count them as shipped, and
     * the order moves to shipped when nothing is left — all in one
     * transaction, checked against the order version the caller saw.
     *
     * @param array<string, mixed> $input the request body
     */
    public function ship(string $id, array $input, Actor $actor): Order
    {
        $check = new OrderInput();
        $expected = $check->integer($input['version'] ?? null, 'version', 1, \PHP_INT_MAX);
        $carrier = $check->text($input['carrier'] ?? null, 'carrier', 64, false);
        $tracking = $check->text($input['trackingNumber'] ?? null, 'trackingNumber', 128, false);
        $shippedAt = $check->instant($input['shippedAt'] ?? null, 'shippedAt');
        $check->throwIfInvalid();
        \assert(null !== $expected);

        $order = $this->get($id);
        $lines = $this->shipmentLines($input['lines'] ?? null, $order, $check);
        $check->throwIfInvalid();

        if ($order->version() !== $expected) {
            throw $this->stale($order);
        }

        // Only reached with no violations, so at least one line.
        \assert([] !== $lines);

        $now = $this->clock->now();
        try {
            $this->transaction->run(function () use ($order, $lines, $carrier, $tracking, $shippedAt, $actor, $now): void {
                try {
                    $shipment = $order->ship($lines, $carrier, $tracking, $shippedAt ?? $now, $actor, $now);
                } catch (ShipmentRefused $e) {
                    throw ShipmentRefused::NOT_SHIPPABLE === $e->reason
                        ? new Conflict($e->getMessage(), [['path' => 'lines', 'message' => $e->getMessage(), 'code' => $e->reason]])
                        : new ValidationFailed([['path' => self::linePath($lines, $e->linePosition), 'message' => $e->getMessage(), 'code' => $e->reason]]);
                }
                $this->stock->ship($order, $shipment, $actor, $now);
            });
        } catch (ConcurrentModification) {
            throw $this->stale($order);
        }

        return $order;
    }

    /**
     * `[{lineId, quantity}]`, each line of this order at most once.
     *
     * @return list<array{line: OrderLine, quantity: int}>
     */
    private function shipmentLines(mixed $value, Order $order, OrderInput $check): array
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            $check->violate('lines', 'A shipment needs at least one line.', 'required');

            return [];
        }

        $byId = [];
        foreach ($order->lines() as $line) {
            $byId[(string) $line->id()] = $line;
        }

        $lines = [];
        $seen = [];
        foreach ($value as $index => $entry) {
            $path = \sprintf('lines[%d]', $index);
            if (!\is_array($entry)) {
                $check->violate($path, 'A line must be an object.', 'type');
                continue;
            }
            $lineId = $check->uuid($entry['lineId'] ?? null, $path.'.lineId');
            $quantity = $check->integer($entry['quantity'] ?? null, $path.'.quantity', 1, self::MAX_QUANTITY);
            if (null === $lineId) {
                if (!isset($entry['lineId'])) {
                    $check->violate($path.'.lineId', 'This value is required.', 'required');
                }
                continue;
            }
            $line = $byId[$lineId] ?? null;
            if (null === $line) {
                $check->violate($path.'.lineId', \sprintf('Order %s has no line "%s".', $order->number(), $lineId), 'unknown_line');
                continue;
            }
            if (isset($seen[$lineId])) {
                $check->violate($path.'.lineId', 'Each line once per shipment.', 'duplicate_line');
                continue;
            }
            $seen[$lineId] = true;
            if (null !== $quantity && $quantity > $line->remainingQuantity()) {
                $check->violate($path.'.quantity', \sprintf('Line %d (%s) has %d left to ship.', $line->position(), $line->skuCode(), $line->remainingQuantity()), ShipmentRefused::EXCEEDS_REMAINING);
                continue;
            }
            if (null !== $quantity) {
                $lines[] = ['line' => $line, 'quantity' => $quantity];
            }
        }

        return $lines;
    }

    /** @param list<array{line: OrderLine, quantity: int}> $lines */
    private static function linePath(array $lines, ?int $position): string
    {
        foreach ($lines as $index => $entry) {
            if ($entry['line']->position() === $position) {
                return \sprintf('lines[%d].quantity', $index);
            }
        }

        return 'lines';
    }

    private function stale(Order $order): Conflict
    {
        return new Conflict(
            \sprintf('Order %s was changed by someone else. Reload it and try again.', $order->number()),
            [['path' => 'version', 'message' => 'The order has changed since this version.', 'code' => 'stale_version']],
        );
    }

    /**
     * @param array<string, mixed> $parameters the query string
     *
     * @return Page<Order>
     */
    public function search(array $parameters, int $offset, int $limit): Page
    {
        $check = new OrderInput();

        $statuses = [];
        foreach ($this->list($parameters['status'] ?? null) as $value) {
            $status = OrderStatus::tryFrom($value);
            if (null === $status) {
                $check->violate('status', \sprintf('Unknown status "%s"; one of: %s.', $value, implode(', ', OrderStatus::values())), 'unknown_status');
            } else {
                $statuses[] = $status;
            }
        }

        $paymentStatuses = [];
        foreach ($this->list($parameters['paymentStatus'] ?? null) as $value) {
            $paymentStatus = PaymentStatus::tryFrom($value);
            if (null === $paymentStatus) {
                $check->violate('paymentStatus', \sprintf('Unknown payment status "%s"; one of: %s.', $value, implode(', ', PaymentStatus::values())), 'unknown_payment_status');
            } else {
                $paymentStatuses[] = $paymentStatus;
            }
        }

        $sort = [];
        foreach ($this->list($parameters['sort'] ?? null) as $value) {
            $field = ltrim($value, '-');
            if (!\in_array($field, OrderQuery::SORTABLE, true)) {
                $check->violate('sort', \sprintf('Cannot sort by "%s"; one of: %s, with "-" for descending.', $field, implode(', ', OrderQuery::SORTABLE)), 'unknown_sort');
                continue;
            }
            $sort[] = ['field' => $field, 'desc' => str_starts_with($value, '-')];
        }
        if (\count($sort) > 3) {
            $check->violate('sort', 'Sort by at most three fields.', 'too_many');
        }

        $search = $check->text($parameters['q'] ?? null, 'q', self::MAX_SEARCH, false) ?? '';
        $placedFrom = $check->instant($parameters['placedFrom'] ?? null, 'placedFrom');
        $placedBefore = $check->instant($parameters['placedBefore'] ?? null, 'placedBefore');
        $customerId = $check->uuid($parameters['customer'] ?? null, 'customer');

        $check->throwIfInvalid();

        return $this->orders->search(new OrderQuery(
            statuses: $statuses,
            channels: $this->list($parameters['channel'] ?? null),
            placedFrom: $placedFrom,
            placedBefore: $placedBefore,
            search: $search,
            sort: [] === $sort ? [['field' => 'placedAt', 'desc' => true]] : $sort,
            offset: $offset,
            limit: $limit,
            customerId: $customerId,
            tags: $this->list($parameters['tag'] ?? null),
            paymentStatuses: $paymentStatuses,
        ));
    }

    /**
     * `a,b` or `a` (or `?status[]=a&status[]=b`) as a list of non-empty strings.
     *
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        $parts = \is_array($value) ? $value : explode(',', \is_string($value) ? $value : '');

        return array_values(array_filter(array_map(static fn (mixed $part): string => \is_string($part) ? trim($part) : '', $parts), static fn (string $part): bool => '' !== $part));
    }
}
