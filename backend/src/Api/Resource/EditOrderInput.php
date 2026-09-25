<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of POST /api/orders/{id}/edits. Every property but `version` is
 * optional and declared without a default: one the request left out stays
 * uninitialized and means "unchanged", while `billingAddress: null` clears
 * it. Values are taken as sent and checked by OrderEditService.
 */
final class EditOrderInput
{
    #[ApiProperty(description: 'The order version you last saw; a different one is a 409.', schema: ['type' => 'integer'], required: true)]
    public mixed $version;

    #[ApiProperty(description: 'Changes to the lines; lines not named are left as they are. `{lineId, quantity}` sets a line\'s ordered quantity (0 removes the line); `{sku, quantity, unitPrice, name?}` adds one. A line keeps its SKU, name and price.', schema: ['type' => 'array', 'items' => ['oneOf' => [Schemas::LINE_CHANGE, Schemas::NEW_LINE]]])]
    public mixed $lines;

    #[ApiProperty(description: 'The name and email copied onto the order; either may be left out.', schema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 255], 'email' => ['type' => ['string', 'null'], 'format' => 'email']]])]
    public mixed $customer;

    #[ApiProperty(schema: Schemas::ADDRESS)]
    public mixed $shippingAddress;

    #[ApiProperty(description: 'null clears it: billing is then the shipping address.', schema: ['oneOf' => [Schemas::ADDRESS, ['type' => 'null']]])]
    public mixed $billingAddress;
}
