<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;

final class PaymentStatusInput
{
    #[ApiProperty(schema: Schemas::PAYMENT_STATUS, required: true)]
    public mixed $paymentStatus = null;

    #[ApiProperty(description: 'The order version the caller last saw.', schema: ['type' => 'integer'], required: true)]
    public mixed $version = null;
}
