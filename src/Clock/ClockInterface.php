<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;

interface ClockInterface
{
    /**
     * Get the current time.
     *
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable;
}
