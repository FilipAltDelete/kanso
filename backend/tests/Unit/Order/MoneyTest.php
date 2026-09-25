<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Order;

use Kanso\Core\Internal\Domain\Order\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAddsAndMultipliesInMinorUnits(): void
    {
        $price = Money::of(19_950, 'SEK');

        self::assertTrue(Money::of(59_850, 'SEK')->equals($price->multiply(3)));
        self::assertTrue(Money::of(20_000, 'SEK')->equals($price->add(Money::of(50, 'SEK'))));
        self::assertSame(0, Money::zero('EUR')->amount);
    }

    public function testNeverMixesCurrencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(100, 'SEK')->add(Money::of(100, 'EUR'));
    }

    public function testRefusesSomethingThatIsNotACurrencyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(100, 'sek');
    }

    public function testMultiplyingPastTheIntegerRangeFailsInsteadOfBecomingAFloat(): void
    {
        $this->expectException(\OverflowException::class);

        Money::of(\PHP_INT_MAX, 'SEK')->multiply(2);
    }

    public function testAddingPastTheIntegerRangeFailsInsteadOfBecomingAFloat(): void
    {
        $this->expectException(\OverflowException::class);

        Money::of(\PHP_INT_MAX, 'SEK')->add(Money::of(1, 'SEK'));
    }

    public function testKeepsTheAmountAnInteger(): void
    {
        $total = Money::of(333, 'SEK')->multiply(3);

        self::assertIsInt($total->amount);
        self::assertSame(999, $total->amount);
    }
}
