<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;

class FakeSchedulingMutex implements SchedulingMutex
{
    /** @var array<string, bool> */
    private $scheduled = [];

    /** @var array<string, int> */
    private $createCounts = [];

    /** @var array<string, int> */
    private $existsCounts = [];

    /**
     * Attempt to obtain a scheduling mutex for the given event.
     *
     * @param Event $event
     * @param DateTimeInterface $time
     * @return bool
     */
    public function create(Event $event, DateTimeInterface $time): bool
    {
        $key = $event->mutexName() . $time->format('Hi');
        $this->createCounts[$key] = ($this->createCounts[$key] ?? 0) + 1;

        if ($this->scheduled[$key] ?? false) {
            return false;
        }

        $this->scheduled[$key] = true;
        return true;
    }

    /**
     * Determine if a scheduling mutex exists for the given event.
     *
     * @param Event $event
     * @param DateTimeInterface $time
     * @return bool
     */
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        $key = $event->mutexName() . $time->format('Hi');
        $this->existsCounts[$key] = ($this->existsCounts[$key] ?? 0) + 1;
        return $this->scheduled[$key] ?? false;
    }

    /**
     * Get create call count for a given key.
     *
     * @param string $key
     * @return int
     */
    public function getCreateCount(string $key): int
    {
        return $this->createCounts[$key] ?? 0;
    }

    /**
     * Get exists call count for a given key.
     *
     * @param string $key
     * @return int
     */
    public function getExistsCount(string $key): int
    {
        return $this->existsCounts[$key] ?? 0;
    }

    /**
     * Reset all state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->scheduled = [];
        $this->createCounts = [];
        $this->existsCounts = [];
    }
}
