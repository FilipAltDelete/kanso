<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderEvent;
use Kanso\Core\Internal\Domain\Order\OrderStoreInterface;
use Kanso\Core\Internal\Domain\Order\OrderTag;
use Kanso\Core\Internal\Domain\Order\PaymentStatus;
use Psr\Clock\ClockInterface;

/**
 * What operators record on an order besides moving it through its states:
 * notes, tags and the payment status. Each change writes its order event in
 * the same transaction.
 *
 * Notes and tags need no version: a note only adds, and tags are sent as
 * "add these, remove those", so two people tagging at once cannot undo each
 * other. The payment status replaces a value, so it takes the version the
 * caller saw, as transitions do.
 */
final class OrderAnnotationService
{
    /** How many orders one bulk tag change may touch: a few pages of the list. */
    public const int MAX_BULK = 500;

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderStoreInterface $store,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    public function addNote(string $id, mixed $note, Actor $actor): Order
    {
        $check = new OrderInput();
        $text = $check->text($note, 'note', Order::MAX_NOTE_LENGTH);
        $check->throwIfInvalid();
        \assert(null !== $text);

        $order = $this->orders->get($id);
        $this->transaction->run(fn () => $order->addNote($text, $actor, $this->clock->now()));

        return $order;
    }

    public function changeTags(string $id, mixed $add, mixed $remove, Actor $actor): Order
    {
        $check = new OrderInput();
        [$add, $remove] = $this->tagChange($add, $remove, $check);
        $check->throwIfInvalid();

        $order = $this->orders->get($id);
        $this->write(fn () => $this->applyTags($order, $add, $remove, $actor, $this->clock->now(), 'add'));

        return $order;
    }

    /**
     * The same change on many orders, all or none: one unknown order or one
     * order over the tag limit and nothing is changed.
     *
     * @return int how many orders' tags changed
     */
    public function bulkChangeTags(mixed $ids, mixed $add, mixed $remove, Actor $actor): int
    {
        $check = new OrderInput();

        $wanted = [];
        if (!\is_array($ids) || !array_is_list($ids) || [] === $ids) {
            $check->violate('orders', 'Name at least one order.', 'required');
        } elseif (\count($ids) > self::MAX_BULK) {
            $check->violate('orders', \sprintf('Change at most %d orders at a time.', self::MAX_BULK), 'too_many');
        } else {
            foreach ($ids as $index => $id) {
                $uuid = $check->uuid($id, \sprintf('orders[%d]', $index));
                if (null !== $uuid) {
                    $wanted[$index] = strtolower($uuid);
                }
            }
        }
        [$add, $remove] = $this->tagChange($add, $remove, $check);

        $found = [];
        foreach ($this->store->findByIds(array_values(array_unique($wanted))) as $order) {
            $found[strtolower((string) $order->id())] = $order;
        }
        foreach ($wanted as $index => $id) {
            if (!isset($found[$id])) {
                $check->violate(\sprintf('orders[%d]', $index), \sprintf('No order "%s".', $id), 'unknown_order');
            }
        }
        $check->throwIfInvalid();

        $now = $this->clock->now();
        $changed = 0;
        $this->write(function () use ($found, $add, $remove, $actor, $now, &$changed): void {
            foreach ($found as $order) {
                if (null !== $this->applyTags($order, $add, $remove, $actor, $now, 'orders')) {
                    ++$changed;
                }
            }
        });

        return $changed;
    }

    /**
     * @param list<string> $add
     * @param list<string> $remove
     */
    private function applyTags(Order $order, array $add, array $remove, Actor $actor, \DateTimeImmutable $now, string $path): ?OrderEvent
    {
        try {
            return $order->changeTags($add, $remove, $actor, $now);
        } catch (\DomainException $e) {
            throw new ValidationFailed([['path' => $path, 'message' => $e->getMessage(), 'code' => 'too_many_tags']]);
        }
    }

    public function changePaymentStatus(string $id, mixed $paymentStatus, mixed $version, Actor $actor): Order
    {
        $check = new OrderInput();
        $name = $check->text($paymentStatus, 'paymentStatus', 32);
        $status = null === $name ? null : PaymentStatus::tryFrom($name);
        if (null !== $name && null === $status) {
            $check->violate('paymentStatus', \sprintf('Unknown payment status "%s"; one of: %s.', $name, implode(', ', PaymentStatus::values())), 'unknown_payment_status');
        }
        $expected = $check->integer($version, 'version', 1, \PHP_INT_MAX);
        $check->throwIfInvalid();
        \assert(null !== $status && null !== $expected);

        $order = $this->orders->get($id);
        if ($order->version() !== $expected) {
            throw $this->stale($order);
        }

        try {
            $this->transaction->run(fn () => $order->changePaymentStatus($status, $actor, $this->clock->now()));
        } catch (ConcurrentModification) {
            throw $this->stale($order);
        }

        return $order;
    }

    /** @return list<array{name: string, orders: int}> every tag in use, by name */
    public function tags(): array
    {
        return $this->store->tagCounts();
    }

    /**
     * `add` and `remove` as tag lists; at least one tag between them, and none in both.
     *
     * @return array{list<string>, list<string>}
     */
    private function tagChange(mixed $add, mixed $remove, OrderInput $check): array
    {
        $add = $this->tagList($add, 'add', $check);
        $remove = $this->tagList($remove, 'remove', $check);

        if ([] === $add && [] === $remove) {
            $check->violate('add', 'Name at least one tag to add or remove.', 'required');
        }
        foreach ($add as $tag) {
            foreach ($remove as $other) {
                if (OrderTag::same($tag, $other)) {
                    $check->violate('remove', \sprintf('"%s" is both added and removed.', $tag), 'conflicting_tags');
                }
            }
        }

        return [$add, $remove];
    }

    /** @return list<string> */
    private function tagList(mixed $value, string $path, OrderInput $check): array
    {
        if (null === $value) {
            return [];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            $check->violate($path, 'This value must be a list of tags.', 'type');

            return [];
        }
        if (\count($value) > Order::MAX_TAGS) {
            $check->violate($path, \sprintf('At most %d tags.', Order::MAX_TAGS), 'too_many');

            return [];
        }

        $tags = [];
        foreach ($value as $index => $tag) {
            $itemPath = \sprintf('%s[%d]', $path, $index);
            if (!\is_string($tag)) {
                $check->violate($itemPath, 'A tag must be text.', 'type');
                continue;
            }
            try {
                $tags[] = OrderTag::normalize($tag);
            } catch (\InvalidArgumentException $e) {
                $check->violate($itemPath, $e->getMessage(), 'tag');
            }
        }

        return $tags;
    }

    /** A tag added by someone else between our read and our write is a conflict to retry. */
    private function write(callable $work): void
    {
        try {
            $this->transaction->run($work);
        } catch (ConcurrentModification) {
            throw new Conflict('The tags were changed by someone else at the same time. Try again.');
        }
    }

    private function stale(Order $order): Conflict
    {
        return new Conflict(
            \sprintf('Order %s was changed by someone else. Reload it and try again.', $order->number()),
            [['path' => 'version', 'message' => 'The order has changed since this version.', 'code' => 'stale_version']],
        );
    }
}
