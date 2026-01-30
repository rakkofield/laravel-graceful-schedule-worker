<?php

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
