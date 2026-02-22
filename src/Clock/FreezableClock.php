<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;
use LogicException;

class FreezableClock implements ClockInterface
{
    /** @var ClockInterface */
    private $inner;

    /** @var DateTimeImmutable|null */
    private $frozenTime = null;

    /**
     * @param ClockInterface $inner
     */
    public function __construct(ClockInterface $inner)
    {
        $this->inner = $inner;
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozenTime !== null ? $this->frozenTime : $this->inner->now();
    }

    /**
     * Execute a callback with the clock frozen at the specified time.
     *
     * The clock is always unfrozen after the callback, even if it throws an exception.
     * Nested calls are not supported (throws LogicException).
     *
     * @param DateTimeImmutable $time The time to freeze at
     * @param callable $callback The callback to execute
     * @return mixed The callback's return value
     * @throws LogicException If called in a nested context
     */
    public function withFrozenTime(DateTimeImmutable $time, callable $callback)
    {
        if ($this->frozenTime !== null) {
            throw new LogicException('FreezableClock::withFrozenTime() cannot be nested.');
        }
        $this->frozenTime = $time;
        try {
            return $callback();
        } finally {
            $this->frozenTime = null;
        }
    }
}
