<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

/**
 * Event Stub that allows setting mutexName() to an arbitrary value
 */
class StubLongMutexEvent extends Event
{
    /** @var string */
    private $customMutexName;

    /**
     * @param EventMutex $mutex
     * @param string $command
     * @param string $mutexName
     */
    public function __construct(EventMutex $mutex, string $command, string $mutexName)
    {
        parent::__construct($mutex, $command);
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
