<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Document\Document;
use Kanso\Core\Internal\Domain\Document\DocumentStatus;
use Kanso\Core\Internal\Domain\Document\DocumentStoreInterface;
use Kanso\Core\Internal\Domain\Document\DocumentType;
use Symfony\Component\Uid\Uuid;

final class DocumentRepository implements DocumentStoreInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?Document
    {
        return Uuid::isValid($id) ? $this->em->find(Document::class, Uuid::fromString($id)) : null;
    }

    public function findReusable(DocumentType $type, Uuid $orderId, int $orderVersion, string $locale, \DateTimeImmutable $pendingSince): ?Document
    {
        /** @var Document|null $document */
        $document = $this->em->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.orderId = :order AND d.type = :type AND d.orderVersion = :version AND d.locale = :locale')
            ->andWhere('d.status = :done OR (d.status IN (:pending) AND d.createdAt >= :since)')
            ->setParameter('order', $orderId, 'uuid')
            ->setParameter('type', $type->value)
            ->setParameter('version', $orderVersion)
            ->setParameter('locale', $locale)
            ->setParameter('done', DocumentStatus::Done->value)
            ->setParameter('pending', [DocumentStatus::Queued->value, DocumentStatus::Running->value])
            ->setParameter('since', $pendingSince)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $document;
    }

    public function save(Document $document): void
    {
        $this->em->persist($document);
        $this->em->flush();
    }
}
