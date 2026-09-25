<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use Kanso\Core\Internal\Api\State\AnnotateOrderProcessor;
use Kanso\Core\Internal\Api\State\CreateOrderProcessor;
use Kanso\Core\Internal\Api\State\CreateShipmentProcessor;
use Kanso\Core\Internal\Api\State\OrderProvider;
use Kanso\Core\Internal\Api\State\TransitionOrderProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * An order as the API shows it. Money is integer minor units of `currency`;
 * timestamps are UTC. The list shows the summary fields; the detail adds
 * addresses, lines, the event timeline and the transitions allowed now.
 * Notes are events in the timeline, of type `note`.
 */
#[ApiResource(
    shortName: 'Order',
    operations: [
        new GetCollection(
            uriTemplate: '/orders',
            provider: OrderProvider::class,
            normalizationContext: ['groups' => ['order:list']],
            parameters: [
                'status' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated statuses: pending, confirmed, allocated, picking, packed, shipped, delivered, cancelled, on_hold.'),
                'channel' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated channel codes.'),
                'placedFrom' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date-time'], description: 'Placed at or after this instant (ISO 8601 with offset, or a date meaning UTC midnight).'),
                'placedBefore' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date-time'], description: 'Placed before this instant.'),
                'shippedFrom' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date-time'], description: 'With a shipment shipped at or after this instant (a partly shipped order counts).'),
                'shippedBefore' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date-time'], description: 'With a shipment shipped before this instant.'),
                'q' => new QueryParameter(schema: ['type' => 'string'], description: 'Search the order number, external reference, customer name and customer email.'),
                'tag' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated tags; orders with any of them. Case does not matter.'),
                'paymentStatus' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated payment statuses: unpaid, authorized, paid, refunded, partially_refunded.'),
                'customer' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'Only orders linked to this customer record.'),
                'sort' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated fields, "-" for descending: placedAt, number, total, customerName, status. Default -placedAt.'),
            ],
        ),
        new Get(
            uriTemplate: '/orders/{id}',
            provider: OrderProvider::class,
            normalizationContext: ['groups' => ['order:list', 'order:detail']],
        ),
        new Post(
            uriTemplate: '/orders',
            status: 201,
            security: "is_granted('ROLE_OPERATOR')",
            input: CreateOrderInput::class,
            processor: CreateOrderProcessor::class,
            normalizationContext: ['groups' => ['order:list', 'order:detail']],
            description: 'Create an order manually. It starts as pending.',
        ),
        new Post(
            uriTemplate: '/orders/{id}/shipments',
            status: 201,
            security: "is_granted('ROLE_OPERATOR')",
            input: ShipmentInput::class,
            read: false,
            processor: CreateShipmentProcessor::class,
            normalizationContext: ['groups' => ['order:list', 'order:detail']],
            description: 'Ship some or all of what is left: lines and quantities (part of a line is fine), carrier and tracking number. The units come off stock on hand and off the reservation; the order becomes shipped when nothing is left. Send the version you last saw: 409 if the order changed since, or if it is not in a status that ships. Answers with the order.',
        ),
        new Post(
            uriTemplate: '/orders/{id}/transitions',
            status: 200,
            security: "is_granted('ROLE_OPERATOR')",
            input: TransitionInput::class,
            read: false,
            processor: TransitionOrderProcessor::class,
            normalizationContext: ['groups' => ['order:list', 'order:detail']],
            description: 'Move the order through its state machine. Send the version you last saw: 409 if the order changed since, or if the transition is not allowed from its status.',
        ),
        new Post(
            uriTemplate: '/orders/{id}/notes',
            status: 200,
            security: "is_granted('ROLE_OPERATOR')",
            input: NoteInput::class,
            read: false,
            processor: AnnotateOrderProcessor::class,
            normalizationContext: ['groups' => ['order:list', 'order:detail']],
            description: 'Add a note, in any status. It appears in the timeline as a `note` event with who wrote it and when. Needs no version, and does not change it.',
        ),
        new Post(
            uriTemplate: '/orders/{id}/tags',
            status: 200,
            security: "is_granted('ROLE_OPERATOR')",
            input: TagChangeInput::class,
            read: false,
            processor: AnnotateOrderProcessor::class,
            normalizationContext: ['groups' => ['order:list', 'order:detail']],
            description: 'Add and remove tags (at most 20 per order). Needs no version, and does not change it: adding and removing by name cannot undo someone else\'s change.',
        ),
        new Post(
            uriTemplate: '/orders/bulk-tags',
            status: 204,
            security: "is_granted('ROLE_OPERATOR')",
            input: BulkTagChangeInput::class,
            output: false,
            read: false,
            processor: AnnotateOrderProcessor::class,
            description: 'Add and remove tags on up to 500 orders at once, all or none: an unknown order, or one that would pass 20 tags, changes nothing.',
        ),
        new Post(
            uriTemplate: '/orders/{id}/payment-status',
            status: 200,
            security: "is_granted('ROLE_OPERATOR')",
            input: PaymentStatusInput::class,
            read: false,
            processor: AnnotateOrderProcessor::class,
            normalizationContext: ['groups' => ['order:list', 'order:detail']],
            description: 'Set the payment status by hand. Send the version you last saw: 409 if the order changed since. Only the status is kept, never card data.',
        ),
    ],
    security: "is_granted('ROLE_VIEWER')",
)]
final class OrderResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['order:list'])]
    public string $id = '';

    #[Groups(['order:list'])]
    public string $number = '';

    /** The order's number in the system it came from; unique per channel. Null for orders entered by hand. */
    #[Groups(['order:list'])]
    public ?string $externalReference = null;

    #[Groups(['order:list'])]
    public string $status = '';

    /** Set while on hold: the status a release returns to. */
    #[Groups(['order:list'])]
    public ?string $heldFrom = null;

    /** @var array{code: string, name: string} */
    #[ApiProperty(schema: ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string']]])]
    #[Groups(['order:list'])]
    public array $channel = ['code' => '', 'name' => ''];

    #[Groups(['order:list'])]
    public string $currency = '';

    #[ApiProperty(schema: Schemas::PAYMENT_STATUS)]
    #[Groups(['order:list'])]
    public string $paymentStatus = 'unpaid';

    /** @var list<string> alphabetical */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups(['order:list'])]
    public array $tags = [];

    /**
     * Where the order's stock is reserved and shipped from.
     *
     * @var array{id: string, code: string, name: string}|null
     */
    #[ApiProperty(schema: Schemas::LOCATION_REF)]
    #[Groups(['order:detail'])]
    public ?array $location = null;

    /** Sum of the line totals, in minor units. */
    #[Groups(['order:list'])]
    public int $total = 0;

    /** @var array{id: string|null, name: string, email: string|null} */
    #[ApiProperty(schema: ['type' => 'object', 'properties' => ['id' => ['type' => ['string', 'null'], 'format' => 'uuid'], 'name' => ['type' => 'string'], 'email' => ['type' => ['string', 'null']]]])]
    #[Groups(['order:list'])]
    public array $customer = ['id' => null, 'name' => '', 'email' => null];

    #[Groups(['order:list'])]
    public int $lineCount = 0;

    #[Groups(['order:list'])]
    public ?\DateTimeImmutable $placedAt = null;

    #[Groups(['order:list'])]
    public ?\DateTimeImmutable $updatedAt = null;

    /** Send this back with a transition; it changes with every change to the order. */
    #[Groups(['order:list'])]
    public int $version = 0;

    #[Groups(['order:detail'])]
    public ?\DateTimeImmutable $createdAt = null;

    /** @var array<string, string|null> */
    #[ApiProperty(schema: Schemas::ADDRESS)]
    #[Groups(['order:detail'])]
    public array $shippingAddress = [];

    /** @var array<string, string|null>|null */
    #[ApiProperty(schema: ['oneOf' => [Schemas::ADDRESS, ['type' => 'null']]])]
    #[Groups(['order:detail'])]
    public ?array $billingAddress = null;

    /** @var list<array<string, mixed>> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => Schemas::LINE])]
    #[Groups(['order:detail'])]
    public array $lines = [];

    /** @var list<array<string, mixed>> oldest first */
    #[ApiProperty(schema: ['type' => 'array', 'items' => Schemas::EVENT])]
    #[Groups(['order:detail'])]
    public array $events = [];

    /** @var list<string> `ship` is never listed: an order ships through shipments. */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups(['order:detail'])]
    public array $availableTransitions = [];

    /** @var list<array<string, mixed>> oldest first */
    #[ApiProperty(schema: ['type' => 'array', 'items' => Schemas::SHIPMENT])]
    #[Groups(['order:detail'])]
    public array $shipments = [];

    /** Whether a shipment can be recorded now: confirmed to packed, not on hold, units left. */
    #[Groups(['order:detail'])]
    public bool $canShip = false;
}
