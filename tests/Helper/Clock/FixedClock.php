<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;

class FixedClock implements ClockInterface
{
    /**
     * @var DateTimeImmutable
     */
    private $fixedTime;

    /**
     * @param DateTimeImmutable $fixedTime
     */
    public function __construct(DateTimeImmutable $fixedTime)
    {
        $this->fixedTime = $fixedTime;
    }

    /**
     * Get the fixed time.
     *
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable
    {
        return $this->fixedTime;
    }

    /**
     * Set a new fixed time.
     *
     * @param DateTimeImmutable $time
     * @return void
     */
    public function setTime(DateTimeImmutable $time)
    {
        $this->fixedTime = $time;
    }
}
