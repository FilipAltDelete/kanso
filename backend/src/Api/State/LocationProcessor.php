<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\LocationInput;
use Kanso\Core\Internal\Api\Resource\LocationPatch;
use Kanso\Core\Internal\Api\Resource\LocationResource;
use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<LocationInput|LocationPatch, LocationResource>
 */
final class LocationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly LocationStoreInterface $locations,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LocationResource
    {
        if ($data instanceof LocationInput) {
            return LocationProvider::present($this->catalog->createLocation(
                $data->code,
                $data->name,
                new Address($data->addressLine1, $data->addressLine2, $data->postalCode, $data->city, $data->countryCode),
            ));
        }

        \assert($data instanceof LocationPatch);
        $location = $this->locations->findById((string) ($uriVariables['id'] ?? '')) ?? throw new NotFoundHttpException('No such location.');

        if (!Sent::has($data, 'version')) {
            throw new ValidationFailed([['path' => 'version', 'message' => 'Send the version you edited, so a change made in between is not overwritten.', 'code' => 'required']]);
        }

        $current = $location->address();

        return LocationProvider::present($this->catalog->updateLocation(
            $location,
            $data->version,
            Sent::has($data, 'name') ? $data->name : $location->name(),
            new Address(
                Sent::has($data, 'addressLine1') ? $data->addressLine1 : $current->line1,
                Sent::has($data, 'addressLine2') ? $data->addressLine2 : $current->line2,
                Sent::has($data, 'postalCode') ? $data->postalCode : $current->postalCode,
                Sent::has($data, 'city') ? $data->city : $current->city,
                Sent::has($data, 'countryCode') ? $data->countryCode : $current->countryCode,
            ),
        ));
    }
}
