<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\ApiKeyInput;
use Kanso\Core\Internal\Api\Resource\ApiKeyResource;
use Kanso\Core\Internal\Application\Security\ApiKeyService;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Creates a key (the one response with the key in it), or revokes one.
 *
 * @implements ProcessorInterface<ApiKeyInput|null, ApiKeyResource>
 */
final class ApiKeyProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ApiKeyService $apiKeys,
        private readonly ApiKeyStoreInterface $keys,
        private readonly ApiKeyProvider $provider,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ApiKeyResource
    {
        if ($data instanceof ApiKeyInput) {
            // Only a signed-in admin gets here: no key can have the admin role.
            $user = $this->security->getUser()?->getUserIdentifier() ?? throw new AccessDeniedException();
            ['key' => $key, 'plainKey' => $plainKey] = $this->apiKeys->createFromRequest(get_object_vars($data), $user);

            return $this->provider->present($key, $plainKey);
        }

        $id = (string) ($uriVariables['id'] ?? '');
        if (null === $this->keys->findById($id)) {
            throw new NotFoundHttpException('No such API key.');
        }

        return $this->provider->present($this->apiKeys->revoke($id));
    }
}
