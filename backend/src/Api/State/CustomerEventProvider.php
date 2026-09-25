<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\CustomerEventResource;
use Kanso\Core\Internal\Application\Customer\CustomerService;
use Kanso\Core\Internal\Domain\Customer\CustomerEvent;
use Kanso\Core\Internal\Domain\Customer\CustomerStoreInterface;

/**
 * A customer's audit trail, newest first, a page at a time.
 *
 * @implements ProviderInterface<CustomerEventResource>
 */
final class CustomerEventProvider implements ProviderInterface
{
    public function __construct(
        private readonly CustomerService $service,
        private readonly CustomerStoreInterface $customers,
        private readonly Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        // An unknown customer is a 404, not an empty history.
        $customer = $this->service->get((string) ($uriVariables['customerId'] ?? ''));
        $offset = $this->pagination->getOffset($operation, $context);
        $limit = $this->pagination->getLimit($operation, $context);

        return new TraversablePaginator(
            new \ArrayIterator(array_map(self::toResource(...), $this->customers->events($customer, $offset, $limit))),
            $this->pagination->getPage($context),
            $limit,
            $this->customers->countEvents($customer),
        );
    }

    private static function toResource(CustomerEvent $event): CustomerEventResource
    {
        $resource = new CustomerEventResource();
        $resource->id = (string) $event->id();
        $resource->customerId = (string) $event->customerId();
        $resource->type = $event->type();
        $resource->actor = $event->actorLabel();
        $resource->changes = $event->changes();
        $resource->occurredAt = $event->occurredAt();

        return $resource;
    }
}
