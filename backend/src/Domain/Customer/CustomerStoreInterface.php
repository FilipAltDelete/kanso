<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Customer;

interface CustomerStoreInterface
{
    /** Sortable fields, as the API names them. */
    public const array SORTABLE = ['name', 'email', 'createdAt', 'updatedAt'];

    public function findById(string $id): ?Customer;

    public function findByEmail(string $email): ?Customer;

    /**
     * Customers whose name or email contains `$query`, one page of them.
     *
     * @param array<string, 'asc'|'desc'> $sort field (one of SORTABLE) to direction
     *
     * @return list<Customer>
     */
    public function search(string $query, array $sort, int $offset, int $limit): array;

    public function count(string $query): int;

    /**
     * Writes the customer and its audit event in one transaction.
     *
     * @throws DuplicateCustomerEmail when another customer has the email
     */
    public function save(Customer $customer, CustomerEvent $event): void;

    /** @return list<CustomerEvent> newest first */
    public function events(Customer $customer, int $offset, int $limit): array;

    public function countEvents(Customer $customer): int;
}
