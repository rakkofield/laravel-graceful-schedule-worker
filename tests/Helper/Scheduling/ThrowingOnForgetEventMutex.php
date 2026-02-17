<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

/**
 * EventMutex that throws on forget() for testing mutex release failure handling.
 */
class ThrowingOnForgetEventMutex implements EventMutex
{
    /**
     * @var \Exception
     */
    private $exceptionToThrow;

    /**
     * @var array<string, bool>
     */
    private $locks = [];

    /**
     * @var array<string, int>
     */
    private $forgetCounts = [];

    /**
     * @param \Exception $exceptionToThrow
     */
    public function __construct(\Exception $exceptionToThrow)
    {
        $this->exceptionToThrow = $exceptionToThrow;
    }

    /**
     * @param Event $event
     * @return bool
     */
    public function create(Event $event): bool
    {
        $name = $event->mutexName();
        if ($this->locks[$name] ?? false) {
            return false;
        }
        $this->locks[$name] = true;
        return true;
    }

    /**
     * @param Event $event
     * @return bool
     */
    public function exists(Event $event): bool
    {
        return $this->locks[$event->mutexName()] ?? false;
    }

    /**
     * @param Event $event
     * @return void
     * @throws \Exception
     */
    public function forget(Event $event): void
    {
        $name = $event->mutexName();
        $this->forgetCounts[$name] = ($this->forgetCounts[$name] ?? 0) + 1;
        throw $this->exceptionToThrow;
    }

    /**
     * @param string $name
     * @return int
     */
    public function getForgetCount(string $name): int
    {
        return $this->forgetCounts[$name] ?? 0;
    }
}
