<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\LocationResource;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;

/**
 * @implements ProviderInterface<LocationResource>
 */
final class LocationProvider implements ProviderInterface
{
    public function __construct(
        private readonly LocationStoreInterface $locations,
        private readonly ListRequest $list,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            [$request, $page, $limit] = $this->list->from($operation, $context, LocationStoreInterface::SORTABLE);

            return ListRequest::paginator($this->locations->search($request), self::present(...), $page, $limit);
        }

        $location = $this->locations->findById((string) ($uriVariables['id'] ?? ''));

        return null === $location ? null : self::present($location);
    }

    public static function present(Location $location): LocationResource
    {
        $resource = new LocationResource();
        $resource->id = $location->id()->toRfc4122();
        $resource->code = $location->code();
        $resource->name = $location->name();
        $resource->addressLine1 = $location->address()->line1;
        $resource->addressLine2 = $location->address()->line2;
        $resource->postalCode = $location->address()->postalCode;
        $resource->city = $location->address()->city;
        $resource->countryCode = $location->address()->countryCode;
        $resource->version = $location->version();
        $resource->createdAt = $location->createdAt();
        $resource->updatedAt = $location->updatedAt();

        return $resource;
    }
}
