<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderQuery;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\OrderStoreInterface;
use Symfony\Component\Uid\Uuid;

final class OrderRepository implements OrderStoreInterface
{
    /** OrderQuery's sort fields and the columns behind them. */
    private const array SORT_COLUMNS = [
        'placedAt' => 'o.placedAt',
        'number' => 'o.number',
        'total' => 'o.totalAmount',
        'customerName' => 'o.customerName',
        'status' => 'o.status',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?Order
    {
        return Uuid::isValid($id) ? $this->em->find(Order::class, Uuid::fromString($id)) : null;
    }

    public function search(OrderQuery $query): Page
    {
        $builder = $this->em->createQueryBuilder()
            ->select('o', 'c')
            ->from(Order::class, 'o')
            ->join('o.channel', 'c');

        $this->filter($builder, $query);

        foreach ($query->sort as ['field' => $field, 'desc' => $desc]) {
            $builder->addOrderBy(self::SORT_COLUMNS[$field], $desc ? 'DESC' : 'ASC');
        }
        // A stable order for equal values, so paging never repeats or skips a row.
        $builder->addOrderBy('o.id', 'DESC');

        $builder->setFirstResult($query->offset)->setMaxResults($query->limit);
        $paginator = new Paginator($builder->getQuery(), fetchJoinCollection: false);

        /** @var list<Order> $orders */
        $orders = iterator_to_array($paginator->getIterator(), false);

        return new Page($orders, \count($paginator));
    }

    private function filter(QueryBuilder $builder, OrderQuery $query): void
    {
        if ([] !== $query->statuses) {
            $builder->andWhere('o.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (OrderStatus $status): string => $status->value, $query->statuses));
        }
        if ([] !== $query->channels) {
            $builder->andWhere('c.code IN (:channels)')->setParameter('channels', $query->channels);
        }
        if (null !== $query->placedFrom) {
            $builder->andWhere('o.placedAt >= :placedFrom')->setParameter('placedFrom', $query->placedFrom);
        }
        if (null !== $query->placedBefore) {
            $builder->andWhere('o.placedAt < :placedBefore')->setParameter('placedBefore', $query->placedBefore);
        }
        if ('' !== $query->search) {
            // Contains, so a surname finds "Anna Andersson". A scan, but a
            // cheap one at Phase 1 volumes; a search engine is a Phase 4 option.
            $like = '%'.Like::escape($query->search).'%';
            $builder->andWhere('o.number LIKE :search OR o.customerName LIKE :search OR o.customerEmail LIKE :search')
                ->setParameter('search', $like);
        }
    }

    public function nextNumber(): string
    {
        $connection = $this->em->getConnection();
        // LAST_INSERT_ID(expr) makes the increment and the read one atomic
        // step for this connection, however many requests create orders at once.
        $connection->executeStatement('UPDATE order_number_sequence SET next_value = LAST_INSERT_ID(next_value + 1) WHERE id = 1');

        return (string) $connection->lastInsertId();
    }

    public function add(Order $order): void
    {
        $this->em->persist($order);
    }
}
