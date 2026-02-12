<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\EventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

/**
 * Event Stub that allows setting mutexName() to an arbitrary value
 */
class StubLongMutexEvent extends ClockAwareEvent
{
    /** @var string */
    private $customMutexName;

    /**
     * @param EventMutex $mutex
     * @param string $command
     * @param string $mutexName
     * @param ClockInterface $clock
     */
    public function __construct(EventMutex $mutex, string $command, string $mutexName, ClockInterface $clock)
    {
        parent::__construct($mutex, $command, $clock);
        $this->customMutexName = $mutexName;
    }

    /**
     * @return string
     */
    public function mutexName()
    {
        return $this->customMutexName;
    }
}
