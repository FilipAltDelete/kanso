<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Order;

use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\NewOrderLine;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderCustomer;
use Kanso\Core\Internal\Domain\Order\OrderEvent;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\PaymentStatus;
use Kanso\Core\Internal\Domain\Order\Transition;
use Kanso\Core\Internal\Domain\Order\TransitionNotAllowed;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-26 08:00:00', new \DateTimeZone('UTC'));
        $this->actor = new Actor('user-1', 'Ops');
    }

    private function product(string $sku): Product
    {
        return new Product($sku, $sku, null, null, $this->now);
    }

    /** @param list<NewOrderLine>|null $lines */
    private function order(?array $lines = null, string $currency = 'SEK'): Order
    {
        return Order::place(
            '10001',
            new Channel('manual', 'Manual', 'manual', 'SEK', $this->now),
            $currency,
            new Location('WH1', 'Main', new Address(), $this->now),
            new OrderCustomer(null, 'Anna Andersson', 'anna@example.com', ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'], null),
            $lines ?? [new NewOrderLine($this->product('TSHIRT-M'), 'T-shirt, M', 3, 19_950), new NewOrderLine($this->product('SOCKS'), 'Socks', 2, 4_900)],
            $this->now,
            $this->actor,
            $this->now,
        );
    }

    public function testCopiesTheLinesAndTotalsThemInMinorUnits(): void
    {
        $order = $this->order();

        self::assertSame(OrderStatus::Pending, $order->status());
        self::assertSame(3 * 19_950 + 2 * 4_900, $order->total()->amount);
        self::assertSame('SEK', $order->total()->currency);

        [$first, $second] = $order->lines();
        self::assertSame([1, 'TSHIRT-M', 'T-shirt, M', 3, 19_950, 59_850], [$first->position(), $first->skuCode(), $first->name(), $first->quantity(), $first->unitPrice()->amount, $first->lineTotal()->amount]);
        self::assertSame(2, $second->position());
    }

    public function testAFreeLineIsAllowedButANegativePriceIsNot(): void
    {
        self::assertSame(0, $this->order([new NewOrderLine($this->product('GIFT'), 'Gift wrap', 1, 0)])->total()->amount);

        $this->expectException(\InvalidArgumentException::class);
        $this->order([new NewOrderLine($this->product('X'), 'X', 1, -1)]);
    }

    public function testATotalPastTheIntegerRangeIsRefused(): void
    {
        $this->expectException(\OverflowException::class);

        $this->order([new NewOrderLine($this->product('A'), 'A', 2, intdiv(\PHP_INT_MAX, 2) + 1)]);
    }

    public function testAnOrderNeedsLines(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->order([]);
    }

    public function testPlacingRecordsACreatedEvent(): void
    {
        [$event] = $this->order()->events();

        self::assertSame(OrderEvent::CREATED, $event->type());
        self::assertNull($event->before());
        self::assertSame(['status' => 'pending', 'heldFrom' => null, 'paymentStatus' => 'unpaid', 'channel' => 'manual', 'currency' => 'SEK', 'total' => 69_650, 'lines' => 2], $event->after());
        self::assertSame('Ops', $event->actor()->name);
    }

    public function testATransitionChangesTheStatusAndRecordsWhoWhatWhenBeforeAndAfter(): void
    {
        $order = $this->order();
        $later = $this->now->modify('+5 minutes');

        $event = $order->apply(Transition::Confirm, new Actor('api-key:1', 'ERP'), $later);

        self::assertSame(OrderStatus::Confirmed, $order->status());
        self::assertSame($later, $order->updatedAt());
        self::assertCount(2, $order->events());
        self::assertSame($event, $order->events()[1]);
        self::assertSame(OrderEvent::TRANSITION, $event->type());
        self::assertSame('confirm', $event->transition());
        self::assertSame(['status' => 'pending', 'heldFrom' => null], $event->before());
        self::assertSame(['status' => 'confirmed', 'heldFrom' => null], $event->after());
        self::assertSame('api-key:1', $event->actor()->id);
        self::assertSame($later, $event->occurredAt());
    }

    public function testARefusedTransitionChangesNothingAndRecordsNothing(): void
    {
        $order = $this->order();

        try {
            $order->apply(Transition::Ship, $this->actor, $this->now);
            self::fail('Shipping a pending order must be refused.');
        } catch (TransitionNotAllowed $e) {
            self::assertSame('An order that is pending cannot ship.', $e->getMessage());
        }

        self::assertSame(OrderStatus::Pending, $order->status());
        self::assertCount(1, $order->events());
    }

    public function testHoldAndReleaseReturnToWhereTheOrderWas(): void
    {
        $order = $this->order();
        $order->apply(Transition::Confirm, $this->actor, $this->now);
        $order->apply(Transition::Allocate, $this->actor, $this->now);

        $hold = $order->apply(Transition::Hold, $this->actor, $this->now);
        self::assertSame(OrderStatus::OnHold, $order->status());
        self::assertSame(OrderStatus::Allocated, $order->heldFrom());
        self::assertSame(['status' => 'on_hold', 'heldFrom' => 'allocated'], $hold->after());
        self::assertSame([Transition::Cancel, Transition::Release], $order->availableTransitions());

        $release = $order->apply(Transition::Release, $this->actor, $this->now);
        self::assertSame(OrderStatus::Allocated, $order->status());
        self::assertNull($order->heldFrom());
        self::assertSame(['status' => 'on_hold', 'heldFrom' => 'allocated'], $release->before());
    }

    public function testTheWholeLifecycle(): void
    {
        $order = $this->order();
        foreach ([Transition::Confirm, Transition::Allocate, Transition::StartPicking, Transition::Pack, Transition::Ship, Transition::Deliver] as $transition) {
            $order->apply($transition, $this->actor, $this->now);
        }

        self::assertSame(OrderStatus::Delivered, $order->status());
        self::assertSame([], $order->availableTransitions());
        self::assertCount(7, $order->events());
    }

    public function testANoteIsAnEventInAnyStatusAndLeavesTheOrderAlone(): void
    {
        $order = $this->order();
        $order->apply(Transition::Cancel, $this->actor, $this->now);
        $later = $this->now->modify('+1 hour');

        $note = $order->addNote("  Customer called: leave at the door.\n", new Actor('user-2', 'Siv'), $later);

        self::assertSame(OrderEvent::NOTE, $note->type());
        self::assertSame(['note' => 'Customer called: leave at the door.'], $note->after());
        self::assertNull($note->before());
        self::assertSame('Siv', $note->actor()->name);
        self::assertEquals($later, $note->occurredAt());
        self::assertEquals($this->now, $order->updatedAt(), 'a note is not a change to the order');
        self::assertSame(OrderStatus::Cancelled, $order->status());
    }

    public function testAnEmptyOrOverlongNoteIsRefused(): void
    {
        $order = $this->order();

        foreach (['   ', str_repeat('x', Order::MAX_NOTE_LENGTH + 1)] as $text) {
            try {
                $order->addNote($text, $this->actor, $this->now);
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(1, $order->events());
    }

    public function testTagsAreAddedAndRemovedIgnoringCase(): void
    {
        $order = $this->order();

        $added = $order->changeTags(['VIP', ' gift  wrap ', 'vip'], [], $this->actor, $this->now);
        self::assertSame(['gift wrap', 'VIP'], $order->tags());
        self::assertNotNull($added);
        self::assertSame(OrderEvent::TAGS_CHANGED, $added->type());
        self::assertSame(['tags' => []], $added->before());
        self::assertSame(['tags' => ['gift wrap', 'VIP']], $added->after());

        self::assertNull($order->changeTags(['vip'], ['not-there'], $this->actor, $this->now), 'no change, no event');

        $order->changeTags(['Rush'], ['Gift Wrap'], $this->actor, $this->now);
        self::assertSame(['Rush', 'VIP'], $order->tags());
        self::assertTrue($order->hasTag('rush'));
        self::assertCount(3, $order->events());
    }

    public function testATagHasNoCommaAndAnOrderAtMostTwentyTags(): void
    {
        $order = $this->order();

        try {
            $order->changeTags(['a,b'], [], $this->actor, $this->now);
            self::fail('Expected a refusal.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\DomainException::class);
        $order->changeTags(array_map(static fn (int $n): string => 'tag-'.$n, range(1, Order::MAX_TAGS + 1)), [], $this->actor, $this->now);
    }

    public function testThePaymentStatusChangesByHandAndRecordsBeforeAndAfter(): void
    {
        $order = $this->order();
        self::assertSame(PaymentStatus::Unpaid, $order->paymentStatus());
        $later = $this->now->modify('+1 day');

        $event = $order->changePaymentStatus(PaymentStatus::Paid, $this->actor, $later);

        self::assertNotNull($event);
        self::assertSame(OrderEvent::PAYMENT_STATUS_CHANGED, $event->type());
        self::assertSame(['paymentStatus' => 'unpaid'], $event->before());
        self::assertSame(['paymentStatus' => 'paid'], $event->after());
        self::assertSame(PaymentStatus::Paid, $order->paymentStatus());
        self::assertEquals($later, $order->updatedAt());

        self::assertNull($order->changePaymentStatus(PaymentStatus::Paid, $this->actor, $later), 'the same status is no change');
        self::assertNotNull($order->changePaymentStatus(PaymentStatus::PartiallyRefunded, $this->actor, $later), 'any status may follow any other');
        self::assertCount(3, $order->events());
    }
}
