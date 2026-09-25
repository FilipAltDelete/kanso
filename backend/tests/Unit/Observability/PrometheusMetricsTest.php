<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Observability;

use Kanso\Core\Internal\Infrastructure\Observability\PrometheusMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** What is decided before Redis is touched: a caller cannot invent a series or drop a label. */
#[CoversClass(PrometheusMetrics::class)]
final class PrometheusMetricsTest extends TestCase
{
    public function testAnUndeclaredMetricIsAProgrammingError(): void
    {
        $this->expectExceptionMessage('No metric "orders_total" is declared');

        new PrometheusMetrics(new \Redis())->increment('orders_total');
    }

    public function testAMissingLabelIsAProgrammingError(): void
    {
        $this->expectExceptionMessage('declares the label "result"');

        new PrometheusMetrics(new \Redis())->increment('messenger_messages_total', ['transport' => 'async']);
    }

    public function testAnUnreachableRedisNeverFailsTheCaller(): void
    {
        // Never connected: every command throws RedisException.
        $metrics = new PrometheusMetrics(new \Redis());

        $metrics->increment('messenger_messages_total', ['transport' => 'async', 'result' => 'handled']);
        $metrics->set('build_info', ['version' => '1.0.0', 'env' => 'test'], 1.0);

        self::assertStringContainsString('kanso_build_info{version="1.0.0",env="test"} 1', $metrics->render());
    }
}
