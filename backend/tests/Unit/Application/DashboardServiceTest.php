<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Application;

use Kanso\Core\Internal\Application\Dashboard\DashboardService;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Dashboard\DashboardQueryInterface;
use Kanso\Core\Internal\Domain\Dashboard\DashboardSummary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** "Today" is the caller's calendar day, whatever the clock says in UTC. */
final class DashboardServiceTest extends TestCase
{
    /** @var DashboardQueryInterface&object{asked: list<array{\DateTimeImmutable, \DateTimeImmutable}>} */
    private DashboardQueryInterface $query;

    public function testLateEveningInUtcIsAlreadyTomorrowInStockholm(): void
    {
        $result = $this->service('2026-09-26 22:30:00')->summary('Europe/Stockholm');

        self::assertSame('2026-09-27', $result['date']);
        self::assertSame('2026-09-26T22:00:00+00:00', $result['dayStart']->format(\DATE_ATOM));
        self::assertSame('2026-09-27T22:00:00+00:00', $result['dayEnd']->format(\DATE_ATOM));
        self::assertEquals([$result['dayStart'], $result['dayEnd']], $this->query->asked[0], 'the query counts that day');
    }

    public function testADayWithTheClocksGoingBackIsTwentyFiveHoursLong(): void
    {
        $result = $this->service('2026-10-25 12:00:00')->summary('Europe/Stockholm');

        self::assertSame('2026-10-24T22:00:00+00:00', $result['dayStart']->format(\DATE_ATOM));
        self::assertSame('2026-10-25T23:00:00+00:00', $result['dayEnd']->format(\DATE_ATOM));
    }

    public function testWithoutATimeZoneTodayIsUtcs(): void
    {
        $result = $this->service('2026-09-26 22:30:00')->summary(null);

        self::assertSame('UTC', $result['timeZone']);
        self::assertSame('2026-09-26', $result['date']);
        self::assertSame('2026-09-26T00:00:00+00:00', $result['dayStart']->format(\DATE_ATOM));
    }

    public function testAnOldZoneNameIsStillAZone(): void
    {
        self::assertSame('Europe/Kiev', $this->service('2026-09-26 12:00:00')->summary('Europe/Kiev')['timeZone']);
    }

    public function testNonsenseIsRefused(): void
    {
        foreach (['Mars/Olympus', ['Europe/Stockholm'], '+02:00'] as $zone) {
            try {
                $this->service('2026-09-26 12:00:00')->summary($zone);
                self::fail('Expected a violation for '.json_encode($zone));
            } catch (ValidationFailed $e) {
                self::assertSame('invalid_time_zone', $e->violations()[0]['code']);
            }
        }
    }

    private function service(string $utcNow): DashboardService
    {
        $this->query = new class implements DashboardQueryInterface {
            /** @var list<array{\DateTimeImmutable, \DateTimeImmutable}> the days it was asked to count */
            public array $asked = [];

            public function summary(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd, int $stockOutLimit): DashboardSummary
            {
                $this->asked[] = [$dayStart, $dayEnd];

                return new DashboardSummary(0, 0, 0, [], 0, []);
            }
        };

        return new DashboardService($this->query, new MockClock($utcNow, 'UTC'));
    }
}
