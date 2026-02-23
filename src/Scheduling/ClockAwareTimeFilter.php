<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

/**
 * Clock-aware time interval filter for schedule events.
 *
 * The parent Event's inTimeInterval() is private and eagerly evaluates Carbon::now() at definition time,
 * so in long-running workers the time gets fixed at startup.
 * This class lazily evaluates clock->now() inside closures to use the correct time each iteration.
 */
class ClockAwareTimeFilter
{
    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var DateTimeZone|null
     */
    private $timezone;

    /**
     * @param ClockInterface $clock
     * @param DateTimeZone|null $timezone
     */
    public function __construct(ClockInterface $clock, ?DateTimeZone $timezone)
    {
        $this->clock = $clock;
        $this->timezone = $timezone;
    }

    /**
     * Generate a closure that checks the time interval using the clock.
     *
     * @param string $startTime "HH:MM" or "HH:MM:SS"
     * @param string $endTime "HH:MM" or "HH:MM:SS"
     * @return Closure
     */
    public function createInterval(string $startTime, string $endTime): Closure
    {
        return function () use ($startTime, $endTime) {
            $now = $this->nowWithTimezone();

            $start = self::applyTimeString($now, $startTime);
            $end = self::applyTimeString($now, $endTime);

            if ($end < $start) {
                if ($start > $now) {
                    $start = $start->modify('-1 day');
                } else {
                    $end = $end->modify('+1 day');
                }
            }

            return $now >= $start && $now <= $end;
        };
    }

    /**
     * Get current time with timezone applied.
     *
     * @return DateTimeImmutable
     */
    public function nowWithTimezone(): DateTimeImmutable
    {
        $now = $this->clock->now();

        if ($this->timezone !== null) {
            $now = $now->setTimezone($this->timezone);
        }

        return $now;
    }

    /**
     * @param DateTimeImmutable $date
     * @param string $timeString "HH:MM" or "HH:MM:SS"
     * @return DateTimeImmutable
     */
    public static function applyTimeString(DateTimeImmutable $date, string $timeString): DateTimeImmutable
    {
        $parts = explode(':', $timeString);
        return $date->setTime((int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));
    }
}
