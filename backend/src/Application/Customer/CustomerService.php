<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Customer;

use Kanso\Core\Internal\Application\Exception\NotFound;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Customer\CountryCode;
use Kanso\Core\Internal\Domain\Customer\Customer;
use Kanso\Core\Internal\Domain\Customer\CustomerAddress;
use Kanso\Core\Internal\Domain\Customer\CustomerEvent;
use Kanso\Core\Internal\Domain\Customer\CustomerStoreInterface;
use Kanso\Core\Internal\Domain\Customer\DuplicateCustomerEmail;
use Kanso\Core\Internal\Domain\Security\Actor;
use Psr\Clock\ClockInterface;

/**
 * Creating and changing customers. Every change is validated here, and every
 * change that is saved writes a CustomerEvent in the same transaction.
 */
final class CustomerService
{
    public const int MAX_ADDRESSES = 50;

    private const string PHONE_PATTERN = '/^\+?[0-9][0-9 ()\-.\/]{3,30}$/';

    public function __construct(
        private readonly CustomerStoreInterface $customers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(CustomerInput $input, Actor $actor): Customer
    {
        $violations = new Violations();
        [$email, $name, $phone] = $this->details($input, null, $violations);

        // Built before the check, so address violations are reported with the rest; never saved invalid.
        $customer = new Customer($email, $name, $phone, $this->clock->now());
        $customer->replaceAddresses($this->addresses($customer, $input->addresses, $violations));
        $violations->throwIfAny();

        $this->save($customer, new CustomerEvent($customer->id(), CustomerEvent::CREATED, $actor, CustomerEvent::diff([], $customer->snapshot()), $customer->createdAt()));

        return $customer;
    }

    /** Replaces the customer's details and addresses with the input; a change with no effect records nothing. */
    public function update(string $id, CustomerInput $input, Actor $actor): Customer
    {
        $customer = $this->customers->findById($id) ?? throw new NotFound(\sprintf('No customer "%s".', $id));
        $before = $customer->snapshot();

        $violations = new Violations();
        [$email, $name, $phone] = $this->details($input, $customer, $violations);
        $addresses = $this->addresses($customer, $input->addresses, $violations);
        $violations->throwIfAny();

        $now = $this->clock->now();
        $customer->changeDetails($email, $name, $phone, $now);
        $customer->replaceAddresses($addresses);

        $changes = CustomerEvent::diff($before, $customer->snapshot());
        if ([] !== $changes) {
            $this->save($customer, new CustomerEvent($customer->id(), CustomerEvent::UPDATED, $actor, $changes, $now));
        }

        return $customer;
    }

    public function get(string $id): Customer
    {
        return $this->customers->findById($id) ?? throw new NotFound(\sprintf('No customer "%s".', $id));
    }

    private function save(Customer $customer, CustomerEvent $event): void
    {
        try {
            $this->customers->save($customer, $event);
        } catch (DuplicateCustomerEmail) {
            throw new ValidationFailed([self::duplicateEmail()]);
        }
    }

    /** @return array{string, string, ?string} */
    private function details(CustomerInput $input, ?Customer $customer, Violations $violations): array
    {
        $email = self::text($input->email, 'email', $violations) ?? '';
        $name = self::text($input->name, 'name', $violations) ?? '';
        $phone = self::text($input->phone, 'phone', $violations);

        if ('' === $email) {
            $violations->add('email', 'An email is required.', 'required');
        } elseif (mb_strlen($email) > 180 || false === filter_var($email, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE)) {
            $violations->add('email', 'This is not a valid email address.', 'invalid_email');
        } else {
            $existing = $this->customers->findByEmail($email);
            if (null !== $existing && $existing !== $customer) {
                $violations->addViolation(self::duplicateEmail());
            }
        }

        if ('' === $name) {
            $violations->add('name', 'A name is required.', 'required');
        } elseif (mb_strlen($name) > 255) {
            $violations->add('name', 'The name is longer than 255 characters.', 'too_long');
        }

        if (null !== $phone && 1 !== preg_match(self::PHONE_PATTERN, $phone)) {
            $violations->add('phone', 'This is not a valid phone number.', 'invalid_phone');
        }

        return [$email, $name, $phone];
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<CustomerAddress>
     */
    private function addresses(Customer $customer, array $rows, Violations $violations): array
    {
        if (\count($rows) > self::MAX_ADDRESSES) {
            $violations->add('addresses', \sprintf('A customer can have at most %d addresses.', self::MAX_ADDRESSES), 'too_many');

            return [];
        }

        $addresses = [];
        $defaults = [];
        foreach (array_values($rows) as $index => $row) {
            $path = \sprintf('addresses[%d]', $index);
            if (!\is_array($row)) {
                $violations->add($path, 'An address is an object.', 'invalid_type');
                continue;
            }

            $address = $this->address($customer, $row, $path, $violations);
            if (null === $address) {
                continue;
            }

            if ($address->isDefault()) {
                if (isset($defaults[$address->type()])) {
                    $violations->add($path.'.isDefault', \sprintf('Only one %s address can be the default.', $address->type()), 'duplicate_default');
                }
                $defaults[$address->type()] = true;
            }
            $addresses[] = $address;
        }

        return $this->withDefaults($addresses, $defaults);
    }

    /**
     * Checks one address and applies it: to the existing address when the row
     * names one of the customer's, otherwise to a new one.
     *
     * @param array<mixed> $row
     */
    private function address(Customer $customer, array $row, string $path, Violations $violations): ?CustomerAddress
    {
        $before = $violations->count();

        $id = self::text($row['id'] ?? null, $path.'.id', $violations);
        $existing = null === $id ? null : $customer->address($id);
        if (null !== $id && null === $existing) {
            $violations->add($path.'.id', 'This address does not belong to the customer.', 'unknown_address');
        }

        $type = self::text($row['type'] ?? null, $path.'.type', $violations);
        if (!\in_array($type, CustomerAddress::TYPES, true)) {
            $violations->add($path.'.type', 'The type is "billing" or "shipping".', 'invalid_choice');
        }

        $default = $row['isDefault'] ?? false;
        if (!\is_bool($default)) {
            $violations->add($path.'.isDefault', 'isDefault is true or false.', 'invalid_type');
        }

        $fields = [];
        foreach (['name' => 255, 'company' => 255, 'line1' => 255, 'line2' => 255, 'postalCode' => 32, 'city' => 128, 'region' => 128, 'phone' => 32] as $field => $max) {
            $fields[$field] = self::text($row[$field] ?? null, $path.'.'.$field, $violations);
            if (null !== $fields[$field] && mb_strlen($fields[$field]) > $max) {
                $violations->add($path.'.'.$field, \sprintf('Longer than %d characters.', $max), 'too_long');
            }
        }
        foreach (['line1', 'postalCode', 'city'] as $required) {
            if (null === $fields[$required]) {
                $violations->add($path.'.'.$required, 'This field is required.', 'required');
            }
        }
        if (null !== $fields['phone'] && 1 !== preg_match(self::PHONE_PATTERN, $fields['phone'])) {
            $violations->add($path.'.phone', 'This is not a valid phone number.', 'invalid_phone');
        }

        $country = strtoupper(self::text($row['countryCode'] ?? null, $path.'.countryCode', $violations) ?? '');
        if (!CountryCode::isAssigned($country)) {
            $violations->add($path.'.countryCode', 'An ISO 3166-1 alpha-2 country code, such as SE.', 'invalid_country');
        }

        if ($violations->count() > $before) {
            return null;
        }

        \assert(\is_string($type) && \is_bool($default));
        $address = $existing ?? new CustomerAddress($customer);
        $address->change(
            $type,
            $default,
            $fields['name'],
            $fields['company'],
            (string) $fields['line1'],
            $fields['line2'],
            (string) $fields['postalCode'],
            (string) $fields['city'],
            $fields['region'],
            $country,
            $fields['phone'],
        );

        return $address;
    }

    /**
     * Every type in use has exactly one default: the first of its addresses
     * when the caller marked none.
     *
     * @param list<CustomerAddress> $addresses
     * @param array<string, true>   $defaults  types that already have one
     *
     * @return list<CustomerAddress>
     */
    private function withDefaults(array $addresses, array $defaults): array
    {
        foreach ($addresses as $address) {
            if (!isset($defaults[$address->type()])) {
                $address->markDefault();
                $defaults[$address->type()] = true;
            }
        }

        return $addresses;
    }

    /** A trimmed string, or null when absent or blank. */
    private static function text(mixed $value, string $path, Violations $violations): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            $violations->add($path, 'Expected a string.', 'invalid_type');

            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /** @return array{path: string, message: string, code: string} */
    private static function duplicateEmail(): array
    {
        return ['path' => 'email', 'message' => 'A customer with this email already exists.', 'code' => 'duplicate'];
    }
}
