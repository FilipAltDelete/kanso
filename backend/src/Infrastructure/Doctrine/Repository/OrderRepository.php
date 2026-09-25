<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\Order\OrderQuery;
use Kanso\Core\Internal\Domain\Order\OrderStatus;
use Kanso\Core\Internal\Domain\Order\OrderStoreInterface;
use Kanso\Core\Internal\Domain\Order\OrderTag;
use Kanso\Core\Internal\Domain\Order\PaymentStatus;
use Symfony\Bridge\Doctrine\Types\UuidType;
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

    public function findByIds(array $ids): array
    {
        $uuids = array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), array_values(array_filter($ids, Uuid::isValid(...))));
        if ([] === $uuids) {
            return [];
        }

        /** @var list<Order> $orders */
        $orders = $this->em->createQueryBuilder()
            ->select('o', 't')
            ->from(Order::class, 'o')
            ->leftJoin('o.tags', 't')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $uuids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();

        return $orders;
    }

    public function tagCounts(): array
    {
        /** @var list<array{name: string, orders: int|string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT MIN(name) AS name, COUNT(*) AS orders FROM order_tag GROUP BY name ORDER BY name');

        return array_map(static fn (array $row): array => ['name' => $row['name'], 'orders' => (int) $row['orders']], $rows);
    }

    public function search(OrderQuery $query): Page
    {
        // The tags are fetched with the page, so the list costs one query, not one per row.
        $builder = $this->em->createQueryBuilder()
            ->select('o', 'c', 't')
            ->from(Order::class, 'o')
            ->join('o.channel', 'c')
            ->leftJoin('o.tags', 't');

        $this->filter($builder, $query);

        foreach ($query->sort as ['field' => $field, 'desc' => $desc]) {
            $builder->addOrderBy(self::SORT_COLUMNS[$field], $desc ? 'DESC' : 'ASC');
        }
        // A stable order for equal values, so paging never repeats or skips a row.
        $builder->addOrderBy('o.id', 'DESC');

        $builder->setFirstResult($query->offset)->setMaxResults($query->limit);
        $paginator = new Paginator($builder->getQuery(), fetchJoinCollection: true);

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
        if (null !== $query->customerId) {
            $builder->andWhere('o.customerId = :customer')->setParameter('customer', Uuid::fromString($query->customerId), UuidType::NAME);
        }
        if ([] !== $query->paymentStatuses) {
            $builder->andWhere('o.paymentStatus IN (:paymentStatuses)')
                ->setParameter('paymentStatuses', array_map(static fn (PaymentStatus $status): string => $status->value, $query->paymentStatuses));
        }
        if ([] !== $query->tags) {
            // A subquery, not the fetch join: filtering that would load only the matching tags.
            $builder->andWhere(\sprintf('EXISTS (SELECT 1 FROM %s ft WHERE ft.order = o AND ft.name IN (:tags))', OrderTag::class))
                ->setParameter('tags', $query->tags);
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
            $builder->andWhere('o.number LIKE :search OR o.externalReference LIKE :search OR o.customerName LIKE :search OR o.customerEmail LIKE :search')
                ->setParameter('search', $like);
        }
    }

    public function findByExternalReferences(Channel $channel, array $references): array
    {
        $found = [];
        // In slices, so a large import stays well inside max_allowed_packet.
        foreach (array_chunk(array_values(array_unique($references)), 1000) as $slice) {
            /** @var list<Order> $orders */
            $orders = $this->em->createQueryBuilder()->select('o')->from(Order::class, 'o')->join('o.channel', 'c')
                ->where('c.code = :channel AND o.externalReference IN (:references)')
                ->setParameter('channel', $channel->code())
                ->setParameter('references', $slice)
                ->getQuery()->getResult();
            array_push($found, ...$orders);
        }

        return $found;
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
