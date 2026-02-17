<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker;

/**
 * Utility for formatting exception messages.
 */
final class ExceptionFormatter
{
    /**
     * @param \Throwable $e
     * @return string
     */
    public static function format(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage();
    }
}
