<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Dashboard;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Dashboard\DashboardQueryInterface;
use Kanso\Core\Internal\Domain\Dashboard\DashboardSummary;
use Psr\Clock\ClockInterface;

/**
 * The dashboard's numbers. "Today" is the caller's calendar day: timestamps
 * are stored in UTC, and an operator in Stockholm means their midnight, not
 * London's. The browser sends its IANA time zone; UTC when it sends none.
 */
final class DashboardService
{
    public const int STOCK_OUT_LIMIT = 10;

    public function __construct(
        private readonly DashboardQueryInterface $query,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array{summary: DashboardSummary, date: string, timeZone: string, dayStart: \DateTimeImmutable, dayEnd: \DateTimeImmutable, generatedAt: \DateTimeImmutable} */
    public function summary(mixed $timeZone): array
    {
        $zone = self::zone($timeZone);
        $now = $this->clock->now();
        $dayStart = $now->setTimezone($zone)->setTime(0, 0);
        // The next midnight, not +24 h: a day with a DST change is 23 or 25 hours long.
        $dayEnd = $dayStart->modify('+1 day');

        $utc = new \DateTimeZone('UTC');

        return [
            'summary' => $this->query->summary($dayStart, $dayEnd, self::STOCK_OUT_LIMIT),
            'date' => $dayStart->format('Y-m-d'),
            'timeZone' => $zone->getName(),
            'dayStart' => $dayStart->setTimezone($utc),
            'dayEnd' => $dayEnd->setTimezone($utc),
            'generatedAt' => $now->setTimezone($utc),
        ];
    }

    private static function zone(mixed $timeZone): \DateTimeZone
    {
        if (null === $timeZone || '' === $timeZone) {
            return new \DateTimeZone('UTC');
        }
        // Including the old names ("Europe/Kiev"): a browser may still report one.
        if (\is_string($timeZone) && \in_array($timeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            return new \DateTimeZone($timeZone);
        }

        throw new ValidationFailed([['path' => 'timeZone', 'message' => 'An IANA time zone, such as Europe/Stockholm.', 'code' => 'invalid_time_zone']]);
    }
}
