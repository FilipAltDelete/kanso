<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/** Every error the API returns speaks RFC 7807. */
final class ProblemResponse
{
    /** @param list<array{path: string, message: string, code: string}> $violations */
    public static function create(int $status, string $title, string $detail, array $violations = []): JsonResponse
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail];
        if ([] !== $violations) {
            $body['violations'] = $violations;
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}
