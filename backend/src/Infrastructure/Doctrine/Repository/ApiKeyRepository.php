<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Symfony\Component\Uid\Uuid;

final class ApiKeyRepository implements ApiKeyStoreInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?ApiKey
    {
        return Uuid::isValid($id) ? $this->em->find(ApiKey::class, Uuid::fromString($id)) : null;
    }

    public function findByHash(string $keyHash): ?ApiKey
    {
        return $this->em->getRepository(ApiKey::class)->findOneBy(['keyHash' => $keyHash]);
    }

    public function save(ApiKey $key): void
    {
        $this->em->persist($key);
        $this->em->flush();
    }
}
