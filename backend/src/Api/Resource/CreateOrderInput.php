<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of POST /api/orders. Values are taken as sent and checked by
 * OrderService, so a wrong type is a violation at its path like any other.
 */
final class CreateOrderInput
{
    #[ApiProperty(description: 'Channel code; default "manual".', schema: ['type' => 'string'])]
    public mixed $channel = null;

    #[ApiProperty(description: 'The order\'s number in the system it came from, such as a webshop. Unique per channel: a second order with the same reference is refused (422 `taken`), so an order cannot be brought in twice.', schema: ['type' => 'string', 'maxLength' => 64])]
    public mixed $externalReference = null;

    #[ApiProperty(description: 'ISO 4217; default the channel currency.', schema: ['type' => 'string', 'pattern' => '^[A-Z]{3}$'])]
    public mixed $currency = null;

    #[ApiProperty(description: 'Code of the location stock is reserved and shipped from; default the installation\'s default location.', schema: ['type' => 'string'])]
    public mixed $location = null;

    #[ApiProperty(description: 'When the customer placed the order; default now.', schema: ['type' => 'string', 'format' => 'date-time'])]
    public mixed $placedAt = null;

    #[ApiProperty(
        description: 'Copied onto the order. `id` optionally refers to a customer record.',
        schema: ['type' => 'object', 'required' => ['name'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string', 'maxLength' => 255], 'email' => ['type' => 'string', 'format' => 'email']]],
        required: true,
    )]
    public mixed $customer = null;

    #[ApiProperty(schema: Schemas::ADDRESS, required: true)]
    public mixed $shippingAddress = null;

    #[ApiProperty(schema: Schemas::ADDRESS)]
    public mixed $billingAddress = null;

    #[ApiProperty(schema: ['type' => 'array', 'minItems' => 1, 'items' => Schemas::NEW_LINE], required: true)]
    public mixed $lines = null;
}
