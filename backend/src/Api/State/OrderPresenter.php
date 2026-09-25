<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use Kanso\Core\Internal\Api\Resource\OrderResource;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\Transition;

/** Order entity to API shape; one place, so every endpoint returns the same order. */
final class OrderPresenter
{
    public static function present(Order $order, bool $detail = false): OrderResource
    {
        $resource = new OrderResource();
        $resource->id = (string) $order->id();
        $resource->number = $order->number();
        $resource->status = $order->status()->value;
        $resource->heldFrom = $order->heldFrom()?->value;
        $resource->channel = ['code' => $order->channel()->code(), 'name' => $order->channel()->name()];
        $resource->currency = $order->currency();
        $resource->total = $order->total()->amount;
        $resource->customer = [
            'id' => null === $order->customerId() ? null : (string) $order->customerId(),
            'name' => $order->customerName(),
            'email' => $order->customerEmail(),
        ];
        $resource->placedAt = $order->placedAt();
        $resource->updatedAt = $order->updatedAt();
        $resource->version = $order->version();

        $lines = $order->lines();
        $resource->lineCount = \count($lines);

        if (!$detail) {
            return $resource;
        }

        $resource->createdAt = $order->createdAt();
        $resource->shippingAddress = $order->shippingAddress();
        $resource->billingAddress = $order->billingAddress();
        foreach ($lines as $line) {
            $resource->lines[] = [
                'id' => (string) $line->id(),
                'position' => $line->position(),
                'sku' => $line->skuCode(),
                'name' => $line->name(),
                'quantity' => $line->quantity(),
                'unitPrice' => $line->unitPrice()->amount,
                'lineTotal' => $line->lineTotal()->amount,
            ];
        }
        foreach ($order->events() as $event) {
            $resource->events[] = [
                'id' => (string) $event->id(),
                'type' => $event->type(),
                'transition' => $event->transition(),
                'actor' => ['id' => $event->actor()->id, 'name' => $event->actor()->name],
                'before' => $event->before(),
                'after' => $event->after(),
                'occurredAt' => $event->occurredAt()->format(\DATE_ATOM),
            ];
        }
        $resource->availableTransitions = array_map(static fn (Transition $transition): string => $transition->value, $order->availableTransitions());

        return $resource;
    }
}
