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
 */
#[ORM\Entity]
#[ORM\Table(name: 'document')]
#[ORM\Index(name: 'idx_document_order', columns: ['order_id', 'type', 'locale', 'order_version'])]
class Document
{
    public const int MAX_ERROR_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: DocumentType::class)]
    private DocumentType $type;

    #[ORM\Column(name: 'order_id', type: UuidType::NAME)]
    private Uuid $orderId;

    /** Copied for the file name. */
    #[ORM\Column(name: 'order_number', length: 32)]
    private string $orderNumber;

    #[ORM\Column(name: 'order_version', type: 'integer')]
    private int $orderVersion;

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
        Uuid $orderId,
        string $orderNumber,
        int $orderVersion,
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

    /** What the browser saves it as, e.g. `pick-list-10001.pdf`, or `packing-slip-10001-2.pdf` for the second shipment. */
    public function filename(): string
    {
        return \sprintf(
            '%s-%s%s.pdf',
            str_replace('_', '-', $this->type->value),
            preg_replace('/[^A-Za-z0-9-]/', '', $this->orderNumber),
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

    public function orderId(): Uuid
    {
        return $this->orderId;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function orderVersion(): int
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
