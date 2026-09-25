<?php

declare(strict_types=1);

namespace Kanso\Api\Controller;

use Kanso\Domain\Health\HealthCheckInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Probes any platform can use: liveness (the process answers) and readiness (its dependencies do). */
final class HealthController
{
    public function __construct(private readonly HealthCheckInterface $health)
    {
    }

    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $checks = $this->health->check();
        $healthy = [] === array_filter($checks, static fn (string $status): bool => 'ok' !== $status);

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
            $healthy ? 200 : 503,
        );
    }
}
