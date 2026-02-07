<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

class FakeEventMutex implements EventMutex
{
    /** @var array<string, bool> */
    private $locks = [];

    /** @var array<string, int> */
    private $createCounts = [];

    /** @var array<string, int> */
    private $existsCounts = [];

    /** @var array<string, int> */
    private $forgetCounts = [];

    /**
     * Attempt to obtain an event mutex for the given event.
     *
     * @param Event $event
     * @return bool
     */
    public function create(Event $event): bool
    {
        $name = $event->mutexName();
        $this->createCounts[$name] = ($this->createCounts[$name] ?? 0) + 1;

        if ($this->locks[$name] ?? false) {
            return false;
        }

        $this->locks[$name] = true;
        return true;
    }

    /**
     * Determine if an event mutex exists for the given event.
     *
     * @param Event $event
     * @return bool
     */
    public function exists(Event $event): bool
    {
        $name = $event->mutexName();
        $this->existsCounts[$name] = ($this->existsCounts[$name] ?? 0) + 1;
        return $this->locks[$name] ?? false;
    }

    /**
     * Clear the event mutex for the given event.
     *
     * @param Event $event
     * @return void
     */
    public function forget(Event $event): void
    {
        $name = $event->mutexName();
        $this->forgetCounts[$name] = ($this->forgetCounts[$name] ?? 0) + 1;
        unset($this->locks[$name]);
    }

    /**
     * Get create call count for a given mutex name.
     *
     * @param string $name
     * @return int
     */
    public function getCreateCount(string $name): int
    {
        return $this->createCounts[$name] ?? 0;
    }

    /**
     * Get exists call count for a given mutex name.
     *
     * @param string $name
     * @return int
     */
    public function getExistsCount(string $name): int
    {
        return $this->existsCounts[$name] ?? 0;
    }

    /**
     * Get forget call count for a given mutex name.
     *
     * @param string $name
     * @return int
     */
    public function getForgetCount(string $name): int
    {
        return $this->forgetCounts[$name] ?? 0;
    }

    /**
     * Reset all state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->locks = [];
        $this->createCounts = [];
        $this->existsCounts = [];
        $this->forgetCounts = [];
    }
}
