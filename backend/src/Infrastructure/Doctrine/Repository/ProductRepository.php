<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
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
}
