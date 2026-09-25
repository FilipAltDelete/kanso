<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Kanso\Core\Internal\Api\Resource\OrderResource;
use Kanso\Core\Internal\Api\Resource\ShipmentCorrectionInput;
use Kanso\Core\Internal\Api\Resource\ShipmentVoidInput;
use Kanso\Core\Internal\Api\Security\CurrentActor;
use Kanso\Core\Internal\Application\Order\OrderService;

/**
 * Voids a shipment, or corrects its carrier and tracking number.
 *
 * @implements ProcessorInterface<ShipmentVoidInput|ShipmentCorrectionInput, OrderResource>
 */
final class ShipmentChangeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CurrentActor $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrderResource
    {
        $id = (string) ($uriVariables['id'] ?? '');
        $shipmentId = (string) ($uriVariables['shipmentId'] ?? '');

        if ($data instanceof ShipmentVoidInput) {
            return OrderPresenter::present($this->orders->voidShipment($id, $shipmentId, get_object_vars($data), $this->actor->get()), detail: true);
        }

        \assert($data instanceof ShipmentCorrectionInput);
        // Only what was sent: a field left out is kept, null clears it.
        $sent = [];
        foreach (['version', 'carrier', 'trackingNumber'] as $field) {
            if (Sent::has($data, $field)) {
                $sent[$field] = $data->{$field};
            }
        }

        return OrderPresenter::present($this->orders->correctShipment($id, $shipmentId, $sent, $this->actor->get()), detail: true);
    }
}
