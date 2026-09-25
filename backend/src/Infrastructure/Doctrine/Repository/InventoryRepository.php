<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\InventoryMovement;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

final class InventoryRepository implements InventoryStoreInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
    }

    public function findLevel(Product $product, Location $location): ?InventoryLevel
    {
        return $this->em->getRepository(InventoryLevel::class)->findOneBy(['product' => $product, 'location' => $location]);
    }

    public function findLevelById(string $id): ?InventoryLevel
    {
        return Uuid::isValid($id) ? $this->em->find(InventoryLevel::class, Uuid::fromString($id)) : null;
    }

    public function lockLevel(Product $product, Location $location): ?InventoryLevel
    {
        $level = $this->em->createQueryBuilder()
            ->select('i')
            ->from(InventoryLevel::class, 'i')
            ->where('i.product = :product AND i.location = :location')
            ->setParameter('product', $product->id(), UuidType::NAME)
            ->setParameter('location', $location->id(), UuidType::NAME)
            ->getQuery()
            // SELECT … FOR UPDATE, and overwrite whatever an earlier read left
            // in the identity map: the locked row is the only truth here.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $level instanceof InventoryLevel ? $level : null;
    }

    public function levels(PageRequest $request): Page
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i', 'p', 'l')
            ->from(InventoryLevel::class, 'i')
            ->join('i.product', 'p')
            ->join('i.location', 'l');

        if (!$this->filter($qb, $request->filters, 'i')) {
            return new Page([], 0);
        }

        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();

        /** @var list<InventoryLevel> $items */
        $items = $qb->orderBy('p.sku', 'asc')
            ->addOrderBy('l.code', 'asc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }

    public function totals(array $productIds): array
    {
        $binary = array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), array_values(array_filter($productIds, Uuid::isValid(...))));
        if ([] === $binary) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_id, SUM(on_hand) AS on_hand, SUM(reserved) AS reserved FROM inventory_level WHERE product_id IN (?) GROUP BY product_id',
            [$binary],
            [ArrayParameterType::BINARY],
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[Uuid::fromBinary((string) $row['product_id'])->toRfc4122()] = [
                'onHand' => (int) $row['on_hand'],
                'reserved' => (int) $row['reserved'],
            ];
        }

        return $totals;
    }

    public function quantities(array $productIds): array
    {
        $binary = array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), array_values(array_unique(array_filter($productIds, Uuid::isValid(...)))));

        $quantities = [];
        // In slices, so a large import stays well inside max_allowed_packet.
        foreach (array_chunk($binary, 1000) as $slice) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT product_id, location_id, on_hand, reserved FROM inventory_level WHERE product_id IN (?)',
                [$slice],
                [ArrayParameterType::BINARY],
            );
            foreach ($rows as $row) {
                $quantities[Uuid::fromBinary((string) $row['product_id'])->toRfc4122()][Uuid::fromBinary((string) $row['location_id'])->toRfc4122()] = [
                    'onHand' => (int) $row['on_hand'],
                    'reserved' => (int) $row['reserved'],
                ];
            }
        }

        return $quantities;
    }

    public function findMovementById(string $id): ?InventoryMovement
    {
        return Uuid::isValid($id) ? $this->em->find(InventoryMovement::class, Uuid::fromString($id)) : null;
    }

    public function movements(PageRequest $request): Page
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m', 'p', 'l')
            ->from(InventoryMovement::class, 'm')
            ->join('m.product', 'p')
            ->join('m.location', 'l');

        if (!$this->filter($qb, $request->filters, 'm')) {
            return new Page([], 0);
        }

        $total = (int) (clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        /** @var list<InventoryMovement> $items */
        $items = $qb->orderBy('m.occurredAt', 'desc')
            // Ids are UUIDv7, so within one second they still sort by creation.
            ->addOrderBy('m.id', 'desc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }

    public function addLevel(InventoryLevel $level): void
    {
        $this->em->persist($level);
    }

    public function addMovement(InventoryMovement $movement): void
    {
        $this->em->persist($movement);
    }

    /**
     * Applies the `product` and `location` id filters. False when an id is not
     * a UUID at all, which can match nothing.
     *
     * @param array<string, string> $filters
     */
    private function filter(QueryBuilder $qb, array $filters, string $alias): bool
    {
        foreach (['product', 'location'] as $field) {
            if (!isset($filters[$field])) {
                continue;
            }
            if (!Uuid::isValid($filters[$field])) {
                return false;
            }
            $qb->andWhere(\sprintf('%s.%s = :%s', $alias, $field, $field))
                ->setParameter($field, Uuid::fromString($filters[$field]), UuidType::NAME);
        }

        return true;
    }
}
