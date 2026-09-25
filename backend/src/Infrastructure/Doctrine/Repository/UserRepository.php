<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use Symfony\Component\Uid\Uuid;

final class UserRepository implements UserStoreInterface
{
    private const array FIELDS = ['email' => 'u.email', 'name' => 'u.name', 'createdAt' => 'u.createdAt'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?User
    {
        return Uuid::isValid($id) ? $this->em->find(User::class, Uuid::fromString($id)) : null;
    }

    public function findByEmail(string $email): ?User
    {
        // The column's collation ignores case.
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function search(PageRequest $request): Page
    {
        $qb = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u');

        if (null !== $request->search && '' !== $request->search) {
            $qb->andWhere('u.email LIKE :contains OR u.name LIKE :contains')->setParameter('contains', '%'.Like::escape($request->search).'%');
        }

        $status = $request->filters['status'] ?? null;
        if ('active' === $status || 'deactivated' === $status) {
            $qb->andWhere('u.enabled = :enabled')->setParameter('enabled', 'active' === $status);
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        foreach ($request->sort ?: ['email' => 'asc'] as $field => $direction) {
            $qb->addOrderBy(self::FIELDS[$field] ?? 'u.email', $direction);
        }

        /** @var list<User> $items */
        $items = $qb->addOrderBy('u.id', 'asc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }

    public function lockActiveAdmins(): array
    {
        /** @var list<User> $admins */
        $admins = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            // `roles` is a JSON list of strings; the quotes keep a role from
            // matching another that contains its name.
            ->where('u.enabled = true AND u.roles LIKE :admin')
            ->setParameter('admin', '%"'.Role::ADMIN.'"%')
            ->orderBy('u.id')
            ->getQuery()
            // SELECT … FOR UPDATE; with no index to narrow it, every user row
            // is locked, so changes to users take turns.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $admins;
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }

    public function count(): int
    {
        return $this->em->getRepository(User::class)->count([]);
    }
}
