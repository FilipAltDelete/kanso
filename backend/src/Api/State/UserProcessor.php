<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\UserInput;
use Kanso\Core\Internal\Api\Resource\UserPasswordInput;
use Kanso\Core\Internal\Api\Resource\UserPatch;
use Kanso\Core\Internal\Api\Resource\UserResource;
use Kanso\Core\Internal\Application\User\UserService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Creates, changes, deactivates and activates users, and sets a password.
 *
 * @implements ProcessorInterface<UserInput|UserPatch|UserPasswordInput|null, UserResource>
 */
final class UserProcessor implements ProcessorInterface
{
    public const string DEACTIVATE = 'user_deactivate';
    public const string ACTIVATE = 'user_activate';
    public const string SET_PASSWORD = 'user_set_password';

    public function __construct(
        private readonly UserService $users,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserResource
    {
        $id = (string) ($uriVariables['id'] ?? '');

        return UserProvider::present(match (true) {
            $data instanceof UserInput => $this->users->createFromRequest(get_object_vars($data)),
            // get_object_vars() leaves out what the patch did not carry.
            $data instanceof UserPatch => $this->users->update($id, get_object_vars($data)),
            $data instanceof UserPasswordInput => $this->users->setPassword($id, $data->password),
            self::DEACTIVATE === $operation->getName() => $this->users->deactivate($id, $this->actorId()),
            self::ACTIVATE === $operation->getName() => $this->users->activate($id),
            default => throw new \LogicException(\sprintf('No user operation "%s".', $operation->getName())),
        });
    }

    private function actorId(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? throw new AccessDeniedException();
    }
}
