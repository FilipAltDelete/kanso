<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\CustomerResource;
use Kanso\Core\Internal\Application\Customer\CustomerInput;
use Kanso\Core\Internal\Application\Customer\CustomerService;
use Kanso\Core\Internal\Application\Security\ActorResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Creates and updates customers through CustomerService, which validates and
 * records the change; this class only translates.
 *
 * @implements ProcessorInterface<CustomerResource, CustomerResource>
 */
final class CustomerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly ActorResolver $actors,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        \assert($data instanceof CustomerResource);

        $input = new CustomerInput($data->email, $data->name, $data->phone, array_values($data->addresses));
        $actor = $this->actors->resolve($this->security->getUser()?->getUserIdentifier());

        $customer = $operation instanceof Patch
            ? $this->customers->update((string) ($uriVariables['id'] ?? ''), $input, $actor)
            : $this->customers->create($input, $actor);

        return CustomerProvider::toResource($customer);
    }
}
