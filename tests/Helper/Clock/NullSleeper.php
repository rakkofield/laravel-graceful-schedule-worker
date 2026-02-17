<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

/**
 * No-op Sleeper for testing (Spy that records call count)
 */
class NullSleeper implements SleeperInterface
{
    /** @var int */
    private $callCount = 0;

    /**
     * {@inheritdoc}
     */
    public function sleep(): void
    {
        $this->callCount++;
    }

    /**
     * Get the number of times sleep() was called
     *
     * @return int
     */
    public function getCallCount(): int
    {
        return $this->callCount;
    }
}
