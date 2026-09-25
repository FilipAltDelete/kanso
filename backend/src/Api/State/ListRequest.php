<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\SerializerContextBuilderInterface;
use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * A list request's query string as a `PageRequest`, with the conventions
 * every Kanso list shares: `q` searches, `sort=name,-createdAt` sorts
 * (unknown fields are ignored), `page` and `itemsPerPage` page.
 */
final class ListRequest
{
    /**
     * Writes deserialize into their own input class, never into the resource
     * the provider loaded; API Platform 4.4 wants that said explicitly.
     */
    public const string ASSIGN_OBJECT = SerializerContextBuilderInterface::ASSIGN_OBJECT_TO_POPULATE;

    public function __construct(private readonly Pagination $pagination)
    {
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string>         $sortable
     * @param list<string>         $filterable query parameters passed through as exact-match filters
     *
     * @return array{PageRequest, int, int} the request, the page number and the page size
     */
    public function from(Operation $operation, array $context, array $sortable = [], array $filterable = []): array
    {
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query : null;

        $sort = [];
        foreach (explode(',', (string) $query?->get('sort', '')) as $field) {
            $field = trim($field);
            $name = ltrim($field, '-');
            if (\in_array($name, $sortable, true) && !isset($sort[$name])) {
                $sort[$name] = str_starts_with($field, '-') ? 'desc' : 'asc';
            }
        }

        $filters = [];
        foreach ($filterable as $name) {
            $value = $query?->get($name);
            if (\is_string($value) && '' !== $value) {
                $filters[$name] = $value;
            }
        }

        $search = trim((string) $query?->get('q', ''));

        return [new PageRequest($offset, $limit, '' === $search ? null : $search, $filters, $sort), (int) $page, (int) $limit];
    }

    /**
     * @template T of object
     * @template R of object
     *
     * @param Page<T>        $page
     * @param callable(T): R $present
     *
     * @return TraversablePaginator<R>
     */
    public static function paginator(Page $page, callable $present, int $pageNumber, int $limit): TraversablePaginator
    {
        return new TraversablePaginator(new \ArrayIterator(array_map($present, $page->items)), $pageNumber, $limit, $page->total);
    }
}
