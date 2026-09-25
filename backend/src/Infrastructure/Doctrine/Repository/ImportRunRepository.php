<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\Import\ImportRun;
use Kanso\Core\Internal\Domain\Import\ImportRunStoreInterface;
use Symfony\Component\Uid\Uuid;

final class ImportRunRepository implements ImportRunStoreInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?ImportRun
    {
        return Uuid::isValid($id) ? $this->em->find(ImportRun::class, Uuid::fromString($id)) : null;
    }

    public function search(PageRequest $request): Page
    {
        $qb = $this->em->createQueryBuilder()->select('r')->from(ImportRun::class, 'r');
        if (isset($request->filters['type'])) {
            $qb->andWhere('r.type = :type')->setParameter('type', $request->filters['type']);
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        /** @var list<ImportRun> $items */
        $items = $qb->orderBy('r.startedAt', 'desc')
            // Ids are UUIDv7, so within one second they still sort by creation.
            ->addOrderBy('r.id', 'desc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }

    public function add(ImportRun $run): void
    {
        $this->em->persist($run);
    }
}
