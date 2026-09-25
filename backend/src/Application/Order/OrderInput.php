<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Order;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Symfony\Component\Uid\Uuid;

/**
 * Checks of request values shared by the order endpoints. Each collects
 * violations instead of throwing, so one response lists every problem.
 */
final class OrderInput
{
    /** @var list<array{path: string, message: string, code: string}> */
    private array $violations = [];

    public function violate(string $path, string $message, string $code): void
    {
        $this->violations[] = ['path' => $path, 'message' => $message, 'code' => $code];
    }

    public function throwIfInvalid(): void
    {
        if ([] !== $this->violations) {
            throw new ValidationFailed($this->violations);
        }
    }

    public function text(mixed $value, string $path, int $max, bool $required = true): ?string
    {
        if (null === $value || (\is_string($value) && '' === trim($value))) {
            if ($required) {
                $this->violate($path, 'This value is required.', 'required');
            }

            return null;
        }
        if (!\is_string($value)) {
            $this->violate($path, 'This value must be text.', 'type');

            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $this->violate($path, \sprintf('This value is longer than %d characters.', $max), 'too_long');

            return null;
        }

        return $value;
    }

    /** JSON integers only: "12" and 12.5 are refused, so an amount is never guessed at. */
    public function integer(mixed $value, string $path, int $min, int $max): ?int
    {
        if (!\is_int($value)) {
            $this->violate($path, null === $value ? 'This value is required.' : 'This value must be a whole number.', null === $value ? 'required' : 'type');

            return null;
        }
        if ($value < $min || $value > $max) {
            $this->violate($path, \sprintf('This value must be between %d and %d.', $min, $max), 'out_of_range');

            return null;
        }

        return $value;
    }

    public function currency(mixed $value, string $path): ?string
    {
        if (!\is_string($value) || 1 !== preg_match('/^[A-Z]{3}$/', $value)) {
            $this->violate($path, 'This value must be a three-letter ISO 4217 currency code, such as SEK.', 'currency');

            return null;
        }

        return $value;
    }

    public function uuid(mixed $value, string $path): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || !Uuid::isValid($value)) {
            $this->violate($path, 'This value must be a UUID.', 'uuid');

            return null;
        }

        return $value;
    }

    public function email(mixed $value, string $path): ?string
    {
        $email = $this->text($value, $path, 255, false);
        if (null !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->violate($path, 'This value is not an email address.', 'email');

            return null;
        }

        return $email;
    }

    /**
     * An ISO 8601 date (UTC midnight) or date-time with an offset, as UTC.
     * Free-form strings such as "tomorrow" are refused.
     */
    public function instant(mixed $value, string $path): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2}))?$/', $value)) {
            $this->violate($path, 'This value must be an ISO 8601 date or date-time with an offset, such as 2026-09-26T08:00:00+02:00.', 'date_time');

            return null;
        }

        try {
            $instant = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $this->violate($path, 'This value is not a valid date.', 'date_time');

            return null;
        }

        return $instant->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return array<string, string|null>|null */
    public function address(mixed $value, string $path, bool $required): ?array
    {
        if (null === $value) {
            if ($required) {
                $this->violate($path, 'This value is required.', 'required');
            }

            return null;
        }
        if (!\is_array($value)) {
            $this->violate($path, 'This value must be an address object.', 'type');

            return null;
        }

        $country = $value['countryCode'] ?? null;
        if (!\is_string($country) || 1 !== preg_match('/^[A-Z]{2}$/', $country)) {
            $this->violate($path.'.countryCode', 'This value must be a two-letter ISO 3166 country code, such as SE.', 'country');
            $country = null;
        }

        return [
            'name' => $this->text($value['name'] ?? null, $path.'.name', 255, false),
            'line1' => $this->text($value['line1'] ?? null, $path.'.line1', 255),
            'line2' => $this->text($value['line2'] ?? null, $path.'.line2', 255, false),
            'postalCode' => $this->text($value['postalCode'] ?? null, $path.'.postalCode', 32),
            'city' => $this->text($value['city'] ?? null, $path.'.city', 128),
            'region' => $this->text($value['region'] ?? null, $path.'.region', 128, false),
            'countryCode' => $country,
            'phone' => $this->text($value['phone'] ?? null, $path.'.phone', 64, false),
        ];
    }
}
