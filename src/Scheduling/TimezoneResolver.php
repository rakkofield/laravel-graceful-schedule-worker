<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeZone;
use InvalidArgumentException;

/**
 * Resolves and validates timezone values.
 *
 * Centralizes timezone resolution logic used by ClockAwareEvent
 * to convert string/DateTimeZone/null inputs into validated DateTimeZone instances.
 */
class TimezoneResolver
{
    /**
     * Resolve a timezone value to a DateTimeZone instance.
     *
     * @param DateTimeZone|string|null $timezone
     * @return DateTimeZone|null
     * @throws InvalidArgumentException If timezone string is invalid
     */
    public static function resolve($timezone): ?DateTimeZone
    {
        if ($timezone === null) {
            return null;
        }
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }
        try {
            return new DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid timezone: %s', $timezone),
                0,
                $e
            );
        }
    }
}
