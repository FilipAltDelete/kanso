<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;
use Symfony\Component\Uid\Uuid;

final class LocationRepository implements LocationStoreInterface
{
    private const array FIELDS = ['code' => 'l.code', 'name' => 'l.name', 'city' => 'l.address.city'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?Location
    {
        return Uuid::isValid($id) ? $this->em->find(Location::class, Uuid::fromString($id)) : null;
    }

    public function findByCode(string $code): ?Location
    {
        return $this->em->getRepository(Location::class)->findOneBy(['code' => $code]);
    }

    public function search(PageRequest $request): Page
    {
        $qb = $this->em->createQueryBuilder()->select('l')->from(Location::class, 'l');

        if (null !== $request->search && '' !== $request->search) {
            $like = Like::escape($request->search);
            $qb->andWhere('l.code LIKE :prefix OR l.name LIKE :contains OR l.address.city LIKE :contains')
                ->setParameter('prefix', $like.'%')
                ->setParameter('contains', '%'.$like.'%');
        }

        $total = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();

        foreach ($request->sort ?: ['code' => 'asc'] as $field => $direction) {
            $qb->addOrderBy(self::FIELDS[$field] ?? 'l.code', $direction);
        }

        /** @var list<Location> $items */
        $items = $qb->addOrderBy('l.id', 'asc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }

    public function add(Location $location): void
    {
        $this->em->persist($location);
    }
}
