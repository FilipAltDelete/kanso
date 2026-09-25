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
use Kanso\Core\Internal\Domain\Order\NewOrderLine;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderCustomer;
use Kanso\Core\Internal\Domain\Order\OrderQuery;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\OrderStoreInterface;
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
        $check = new OrderInput();

        $channelCode = $check->text($input['channel'] ?? Channel::MANUAL, 'channel', 64);
        $channel = null === $channelCode ? null : $this->channels->findByCode($channelCode);
        if (null !== $channelCode && null === $channel) {
            $check->violate('channel', \sprintf('No channel "%s".', $channelCode), 'unknown_channel');
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

        $check->throwIfInvalid();
        \assert(null !== $channel && null !== $currency && null !== $customerName && null !== $shipping && null !== $location);

        $now = $this->clock->now();
        try {
            $order = Order::place(
                $this->orders->nextNumber(),
                $channel,
                $currency,
                $location,
                new OrderCustomer($customerId, $customerName, $customerEmail, $shipping, $billing),
                $lines,
                $placedAt ?? $now,
                $actor,
                $now,
            );
        } catch (\OverflowException) {
            // Each line is within range, but the sum of many is not.
            throw new ValidationFailed([['path' => 'lines', 'message' => 'The order total is too large.', 'code' => 'out_of_range']]);
        }

        $this->transaction->run(fn () => $this->orders->add($order));

        return $order;
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
        } elseif ($heldStock && Transition::Ship === $move) {
            $this->stock->ship($order, $actor, $now);
        } elseif ($heldStock && !$order->holdsStock()) {
            $this->stock->release($order, $actor, $now);
        }
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
