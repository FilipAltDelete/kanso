<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Observability;

use Kanso\Core\Internal\Infrastructure\Observability\PrometheusMetrics;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The shipped dashboards and alert rules name metrics by string, in files no
 * compiler reads. Renaming a series in PrometheusMetrics would leave a panel
 * that is quietly empty and an alert that can never fire — the worst kind of
 * broken, because it looks exactly like "nothing is wrong". So every `kanso_*`
 * name in `observability/` has to be one the code declares.
 *
 * The directory sits beside `backend/` in the repository and is mounted at
 * `/observability` beside `/app` in the tools container, so the same relative
 * path finds it in both.
 */
#[CoversNothing]
final class ObservabilityFilesTest extends TestCase
{
    public function testEveryMetricTheShippedFilesReadIsOneTheCoreDeclares(): void
    {
        $declared = $this->declaredSeries();
        $files = $this->shippedFiles();

        self::assertNotEmpty($files);

        foreach ($files as $file) {
            preg_match_all('/(?<![:\w])kanso_[a-z_]+\b/', (string) file_get_contents($file), $matches);
            self::assertNotEmpty($matches[0], $file.' reads no kanso_* series at all.');

            foreach (array_unique($matches[0]) as $series) {
                self::assertContains($series, $declared, \sprintf('%s reads "%s", which PrometheusMetrics does not declare.', basename($file), $series));
            }
        }
    }

    public function testTheDashboardsAreValidJsonWithStableUids(): void
    {
        $dashboards = glob($this->root().'/grafana/dashboards/*.json') ?: [];
        self::assertNotEmpty($dashboards);

        foreach ($dashboards as $file) {
            $dashboard = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

            self::assertIsArray($dashboard);
            // The fleet table links to the installation dashboard by uid; an
            // import that minted a new one would break the link.
            self::assertSame(basename($file, '.json'), $dashboard['uid'] ?? null);
        }
    }

    /** @return list<string> every series name /metrics can serve, suffixes included */
    private function declaredSeries(): array
    {
        $series = [];
        $prefix = PrometheusMetrics::PREFIX.'_';

        foreach (array_keys(PrometheusMetrics::COUNTERS + PrometheusMetrics::GAUGES) as $name) {
            $series[] = $prefix.$name;
        }

        foreach (array_keys(PrometheusMetrics::HISTOGRAMS) as $name) {
            foreach (['_bucket', '_sum', '_count'] as $suffix) {
                $series[] = $prefix.$name.$suffix;
            }
        }

        return $series;
    }

    /** @return list<string> */
    private function shippedFiles(): array
    {
        return [
            ...glob($this->root().'/prometheus/*.yml') ?: [],
            ...glob($this->root().'/grafana/dashboards/*.json') ?: [],
        ];
    }

    private function root(): string
    {
        $root = \dirname(__DIR__, 4).'/observability';

        if (!is_dir($root)) {
            self::fail(\sprintf('%s is missing: run the suite from the repository, or with it mounted beside /app.', $root));
        }

        return $root;
    }
}
