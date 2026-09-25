<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\DashboardResource;
use Kanso\Core\Internal\Application\Dashboard\DashboardService;
use Kanso\Core\Internal\Domain\Dashboard\StockOut;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<DashboardResource>
 */
final class DashboardProvider implements ProviderInterface
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DashboardResource
    {
        $request = $context['request'] ?? null;
        $result = $this->dashboard->summary($request instanceof Request ? $request->query->all()['timeZone'] ?? null : null);
        $summary = $result['summary'];

        $resource = new DashboardResource();
        $resource->date = $result['date'];
        $resource->timeZone = $result['timeZone'];
        $resource->dayStart = $result['dayStart'];
        $resource->dayEnd = $result['dayEnd'];
        $resource->ordersToday = $summary->ordersToday;
        $resource->awaitingFulfillment = $summary->awaitingFulfillment;
        $resource->shippedToday = $summary->shippedToday;
        $resource->ordersByStatus = $summary->ordersByStatus;
        $resource->stockOuts = [
            'count' => $summary->stockOutCount,
            'items' => array_map(static fn (StockOut $out): array => [
                'productId' => $out->productId,
                'sku' => $out->sku,
                'productName' => $out->productName,
                'locationId' => $out->locationId,
                'locationCode' => $out->locationCode,
                'locationName' => $out->locationName,
                'onHand' => $out->onHand,
                'reserved' => $out->reserved,
            ], $summary->stockOuts),
        ];
        $resource->generatedAt = $result['generatedAt'];

        return $resource;
    }
}
