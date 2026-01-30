<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

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
