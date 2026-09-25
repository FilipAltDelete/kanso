<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Symfony\Component\Uid\Uuid;

final class ApiKeyRepository implements ApiKeyStoreInterface
{
    private const array FIELDS = ['name' => 'k.name', 'role' => 'k.role', 'createdAt' => 'k.createdAt', 'expiresAt' => 'k.expiresAt', 'lastUsedAt' => 'k.lastUsedAt'];

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

    public function search(PageRequest $request): Page
    {
        $qb = $this->em->createQueryBuilder()->select('k')->from(ApiKey::class, 'k');

        if (null !== $request->search && '' !== $request->search) {
            $qb->andWhere('k.name LIKE :contains')->setParameter('contains', '%'.Like::escape($request->search).'%');
        }

        $total = (int) (clone $qb)->select('COUNT(k.id)')->getQuery()->getSingleScalarResult();

        foreach ($request->sort ?: ['createdAt' => 'desc'] as $field => $direction) {
            $qb->addOrderBy(self::FIELDS[$field] ?? 'k.createdAt', $direction);
        }

        /** @var list<ApiKey> $items */
        $items = $qb->addOrderBy('k.id', 'asc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }

    public function save(ApiKey $key): void
    {
        $this->em->persist($key);
        $this->em->flush();
    }
}
