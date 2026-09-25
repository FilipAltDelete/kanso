<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Order;

/**
 * An amount in integer minor units (öre, cents) with its ISO 4217 code. Never
 * a float (CLAUDE.md). Arithmetic refuses to overflow rather than silently
 * turning into a float, and refuses to mix currencies.
 */
final readonly class Money
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {
    }

    public static function of(int $amount, string $currency): self
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an ISO 4217 currency code.', $currency));
        }

        return new self($amount, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException(\sprintf('Cannot add %s to %s.', $other->currency, $this->currency));
        }

        $sum = $this->amount + $other->amount;
        // PHP turns an overflowing int into a float, which static analysis does not model.
        if (!\is_int($sum)) { // @phpstan-ignore function.alreadyNarrowedType
            throw new \OverflowException('The amount is too large.');
        }

        return new self($sum, $this->currency);
    }

    public function multiply(int $factor): self
    {
        $product = $this->amount * $factor;
        if (!\is_int($product)) { // @phpstan-ignore function.alreadyNarrowedType
            throw new \OverflowException('The amount is too large.');
        }

        return new self($product, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
