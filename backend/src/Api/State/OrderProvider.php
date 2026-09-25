<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\OrderResource;
use Kanso\Core\Internal\Application\Exception\NotFound;
use Kanso\Core\Internal\Application\Order\OrderService;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<OrderResource>
 */
final class OrderProvider implements ProviderInterface
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $request = $context['request'] ?? null;
            [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
            $result = $this->orders->search($request instanceof Request ? $request->query->all() : [], $offset, $limit);

            return new TraversablePaginator(
                new \ArrayIterator(array_map(OrderPresenter::present(...), $result->items)),
                $page,
                $limit,
                $result->total,
            );
        }

        try {
            return OrderPresenter::present($this->orders->get((string) ($uriVariables['id'] ?? '')), detail: true);
        } catch (NotFound) {
            return null;
        }
    }
}
