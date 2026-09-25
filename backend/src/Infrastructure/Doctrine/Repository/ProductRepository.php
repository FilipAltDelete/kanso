<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductEvent;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

final class ProductRepository implements ProductStoreInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?Product
    {
        return Uuid::isValid($id) ? $this->em->find(Product::class, Uuid::fromString($id)) : null;
    }

    public function findBySku(string $sku): ?Product
    {
        return $this->em->getRepository(Product::class)->findOneBy(['sku' => $sku]);
    }

    public function findBySkus(array $skus): array
    {
        $found = [];
        // In slices, so a large import stays well inside max_allowed_packet.
        foreach (array_chunk(array_values(array_unique($skus)), 1000) as $slice) {
            /** @var list<Product> $products */
            $products = $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p')
                ->where('p.sku IN (:skus)')->setParameter('skus', $slice)
                ->getQuery()->getResult();
            foreach ($products as $product) {
                $found[$product->sku()] = $product;
            }
        }

        return $found;
    }

    public function search(PageRequest $request): Page
    {
        $qb = $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p');

        if (null !== $request->search && '' !== $request->search) {
            $like = Like::escape($request->search);
            $qb->andWhere('p.sku LIKE :prefix OR p.name LIKE :contains OR p.barcode = :exact')
                ->setParameter('prefix', $like.'%')
                ->setParameter('contains', '%'.$like.'%')
                ->setParameter('exact', $request->search);
        }

        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        foreach ($request->sort ?: ['sku' => 'asc'] as $field => $direction) {
            $qb->addOrderBy('p.'.$field, $direction);
        }

        /** @var list<Product> $items */
        $items = $qb->addOrderBy('p.id', 'asc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }

    public function add(Product $product): void
    {
        $this->em->persist($product);
    }

    public function findEventById(string $id): ?ProductEvent
    {
        return Uuid::isValid($id) ? $this->em->find(ProductEvent::class, Uuid::fromString($id)) : null;
    }

    public function addEvent(ProductEvent $event): void
    {
        $this->em->persist($event);
    }

    public function events(PageRequest $request): Page
    {
        $product = $request->filters['product'] ?? '';
        if (!Uuid::isValid($product)) {
            return new Page([], 0);
        }

        $qb = $this->em->createQueryBuilder()->select('e')->from(ProductEvent::class, 'e')
            ->where('IDENTITY(e.product) = :product')
            ->setParameter('product', Uuid::fromString($product), UuidType::NAME);

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        /** @var list<ProductEvent> $items */
        $items = $qb->orderBy('e.occurredAt', 'desc')
            // Ids are UUIDv7, so within one second they still sort by creation.
            ->addOrderBy('e.id', 'desc')
            ->setFirstResult($request->offset)
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return new Page($items, $total);
    }
}
