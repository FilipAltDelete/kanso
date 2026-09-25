<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Document;

use Doctrine\ORM\Mapping as ORM;
use Kanso\Core\Internal\Domain\Common\Actor;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A generated PDF for an order, and the job that makes it: queued when
 * requested, rendered by a worker, stored in object storage. The order's
 * version is part of the request, so an unchanged order hands back the
 * document it already has, and a changed one gets a fresh one.
 *
 * A batch document (forOrders()) is one PDF for several orders, each on its
 * own pages: it has no single order, but the list of orders with the
 * versions they were requested at, and a key made of them for reuse.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document')]
#[ORM\Index(name: 'idx_document_order', columns: ['order_id', 'type', 'locale', 'order_version'])]
#[ORM\Index(name: 'idx_document_batch', columns: ['batch_key'])]
class Document
{
    public const int MAX_ERROR_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: DocumentType::class)]
    private DocumentType $type;

    /** Null for a batch document. */
    #[ORM\Column(name: 'order_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $orderId;

    /** Copied for the file name. */
    #[ORM\Column(name: 'order_number', length: 32, nullable: true)]
    private ?string $orderNumber;

    #[ORM\Column(name: 'order_version', type: 'integer', nullable: true)]
    private ?int $orderVersion;

    /**
     * A batch document's orders, in the order they are printed, with the
     * versions they had when it was requested.
     *
     * @var list<array{id: string, number: string, version: int}>|null
     */
    #[ORM\Column(name: 'batch_orders', type: 'json', nullable: true)]
    private ?array $batchOrders = null;

    /** A batch document's type, language and orders at their versions, hashed: the same request gets the same document. */
    #[ORM\Column(name: 'batch_key', length: 64, nullable: true)]
    private ?string $batchKey = null;

    /** The language the document is written in: `sv` or `en`. */
    #[ORM\Column(length: 8)]
    private string $locale;

    /** A packing slip for one shipment rather than the whole order; null otherwise. */
    #[ORM\Column(name: 'shipment_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $shipmentId;

    /** Which of the order's shipments it is (1, 2, …), for the filename. */
    #[ORM\Column(name: 'shipment_number', type: 'integer', nullable: true)]
    private ?int $shipmentNumber;

    #[ORM\Column(length: 16, enumType: DocumentStatus::class)]
    private DocumentStatus $status = DocumentStatus::Queued;

    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(name: 'byte_size', type: 'integer', nullable: true)]
    private ?int $byteSize = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'requested_by_id', length: 64)]
    private string $requestedById;

    #[ORM\Column(name: 'requested_by_name', length: 255)]
    private string $requestedByName;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        DocumentType $type,
        ?Uuid $orderId,
        ?string $orderNumber,
        ?int $orderVersion,
        string $locale,
        Actor $requestedBy,
        \DateTimeImmutable $now,
        ?Uuid $shipmentId = null,
        ?int $shipmentNumber = null,
    ) {
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->orderId = $orderId;
        $this->orderNumber = $orderNumber;
        $this->orderVersion = $orderVersion;
        $this->locale = $locale;
        $this->shipmentId = $shipmentId;
        $this->shipmentNumber = $shipmentNumber;
        $this->requestedById = $requestedBy->id;
        $this->requestedByName = $requestedBy->name;
        $this->createdAt = $now;
    }

    /**
     * One PDF for several orders. Its key covers the type, the language and
     * every order at its version, so asking again for the same unchanged
     * orders finds it.
     *
     * @param non-empty-list<array{id: string, number: string, version: int}> $orders in print order
     */
    public static function forOrders(DocumentType $type, array $orders, string $locale, Actor $requestedBy, \DateTimeImmutable $now): self
    {
        $document = new self($type, null, null, null, $locale, $requestedBy, $now);
        $document->batchOrders = $orders;
        $document->batchKey = self::batchKey($type, $orders, $locale);

        return $document;
    }

    /** @param list<array{id: string, number: string, version: int}> $orders */
    public static function batchKey(DocumentType $type, array $orders, string $locale): string
    {
        return hash('sha256', implode('|', [$type->value, $locale, ...array_map(static fn (array $order): string => $order['id'].':'.$order['version'], $orders)]));
    }

    public function isBatch(): bool
    {
        return null !== $this->batchOrders;
    }

    /** @return list<array{id: string, number: string, version: int}> a batch document's orders; empty for one order's */
    public function batchOrders(): array
    {
        return $this->batchOrders ?? [];
    }

    public function start(): void
    {
        $this->status = DocumentStatus::Running;
        $this->error = null;
    }

    public function complete(string $storageKey, int $byteSize, \DateTimeImmutable $now): void
    {
        $this->status = DocumentStatus::Done;
        $this->storageKey = $storageKey;
        $this->byteSize = $byteSize;
        $this->error = null;
        $this->completedAt = $now;
    }

    /** Given up on: the reason is kept for whoever looks, cut to fit. */
    public function fail(string $reason, \DateTimeImmutable $now): void
    {
        $this->status = DocumentStatus::Failed;
        $this->error = mb_substr($reason, 0, self::MAX_ERROR_LENGTH);
        $this->completedAt = $now;
    }

    /** Where the PDF goes in object storage; the same on every attempt, so a retry overwrites. */
    public function intendedStorageKey(): string
    {
        return \sprintf('documents/%s.pdf', $this->id);
    }

    /**
     * What the browser saves it as, e.g. `pick-list-10001.pdf`, or
     * `packing-slip-10001-2.pdf` for the second shipment. A batch is named by
     * when it was asked for (UTC): `pick-lists-20260925-1432.pdf`.
     */
    public function filename(): string
    {
        if ($this->isBatch()) {
            return \sprintf('%ss-%s.pdf', str_replace('_', '-', $this->type->value), $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-Hi'));
        }

        return \sprintf(
            '%s-%s%s.pdf',
            str_replace('_', '-', $this->type->value),
            preg_replace('/[^A-Za-z0-9-]/', '', (string) $this->orderNumber),
            null === $this->shipmentNumber ? '' : '-'.$this->shipmentNumber,
        );
    }

    public function shipmentId(): ?Uuid
    {
        return $this->shipmentId;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function type(): DocumentType
    {
        return $this->type;
    }

    public function orderId(): ?Uuid
    {
        return $this->orderId;
    }

    public function orderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function orderVersion(): ?int
    {
        return $this->orderVersion;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function storageKey(): ?string
    {
        return $this->storageKey;
    }

    public function byteSize(): ?int
    {
        return $this->byteSize;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function requestedBy(): Actor
    {
        return new Actor($this->requestedById, $this->requestedByName);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
