<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\CustomerResource;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Customer\Customer;
use Kanso\Core\Internal\Domain\Customer\CustomerAddress;
use Kanso\Core\Internal\Domain\Customer\CustomerStoreInterface;

/**
 * Reads customers: a searched, sorted page (`?q=`, `?sort=name,-createdAt`,
 * `?page=`, `?itemsPerPage=`, as every Kanso list), or one by id.
 *
 * @implements ProviderInterface<CustomerResource>
 */
final class CustomerProvider implements ProviderInterface
{
    /** Newest first until the caller asks otherwise. */
    private const array DEFAULT_SORT = ['createdAt' => 'desc'];

    public function __construct(
        private readonly CustomerStoreInterface $customers,
        private readonly Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $customer = $this->customers->findById((string) ($uriVariables['id'] ?? ''));

            return null === $customer ? null : self::toResource($customer);
        }

        /** @var array<string, mixed> $filters */
        $filters = $context['filters'] ?? [];
        $query = \is_string($filters['q'] ?? null) ? $filters['q'] : '';
        $offset = $this->pagination->getOffset($operation, $context);
        $limit = $this->pagination->getLimit($operation, $context);

        return new TraversablePaginator(
            new \ArrayIterator(array_map(self::toResource(...), $this->customers->search($query, self::sort($filters['sort'] ?? null), $offset, $limit))),
            $this->pagination->getPage($context),
            $limit,
            $this->customers->count($query),
        );
    }

    public static function toResource(Customer $customer): CustomerResource
    {
        $resource = new CustomerResource();
        $resource->id = (string) $customer->id();
        $resource->email = $customer->email();
        $resource->name = $customer->name();
        $resource->phone = $customer->phone();
        $resource->addresses = array_map(static fn (CustomerAddress $address): array => $address->snapshot(), $customer->addresses());
        $resource->createdAt = $customer->createdAt();
        $resource->updatedAt = $customer->updatedAt();

        return $resource;
    }

    /**
     * `name,-createdAt`: fields in order, "-" for descending. An unknown field
     * is refused rather than ignored, as for orders, so a typo is not a
     * silently unsorted list.
     *
     * @return array<string, 'asc'|'desc'>
     */
    private static function sort(mixed $value): array
    {
        if (null === $value || '' === $value) {
            return self::DEFAULT_SORT;
        }
        if (!\is_string($value)) {
            throw new ValidationFailed([['path' => 'sort', 'message' => 'Sort as sort=field,-field.', 'code' => 'unknown_sort']]);
        }

        $sort = [];
        foreach (array_filter(array_map(trim(...), explode(',', $value))) as $part) {
            $field = ltrim($part, '-');
            if (!\in_array($field, CustomerStoreInterface::SORTABLE, true)) {
                throw new ValidationFailed([['path' => 'sort', 'message' => \sprintf('Cannot sort by "%s"; one of: %s, with "-" for descending.', $field, implode(', ', CustomerStoreInterface::SORTABLE)), 'code' => 'unknown_sort']]);
            }
            $sort[$field] = str_starts_with($part, '-') ? 'desc' : 'asc';
        }

        return [] === $sort ? self::DEFAULT_SORT : $sort;
    }
}
