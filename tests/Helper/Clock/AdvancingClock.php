<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;

/**
 * Clock implementation that advances by a specified number of seconds on each now() call
 */
class AdvancingClock implements ClockInterface
{
    /** @var DateTimeImmutable */
    private $current;

    /** @var int */
    private $advanceSeconds;

    /**
     * @param DateTimeImmutable $start Start time
     * @param int $advanceSeconds Seconds to advance per now() call
     */
    public function __construct(DateTimeImmutable $start, int $advanceSeconds = 1)
    {
        $this->current = $start;
        $this->advanceSeconds = $advanceSeconds;
    }

    public function now(): DateTimeImmutable
    {
        $result = $this->current;
        $this->current = $this->current->modify("+{$this->advanceSeconds} seconds");
        return $result;
    }
}
