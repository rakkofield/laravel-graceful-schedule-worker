<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\Schedule;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

class ClockAwareSchedule extends Schedule
{
    /**
     * @var ClockInterface
     */
    protected $clock;

    /**
     * @param ClockInterface $clock
     * @param \DateTimeZone|string|null $timezone
     */
    public function __construct(ClockInterface $clock, $timezone = null)
    {
        parent::__construct($timezone);
        $this->clock = $clock;
    }

    /**
     * Add a new Artisan command event to the schedule.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return ClockAwareEvent
     */
    public function exec($command, array $parameters = [])
    {
        if (count($parameters)) {
            $command .= ' ' . $this->compileParameters($parameters);
        }

        $event = new ClockAwareEvent($this->eventMutex, $command, $this->clock, $this->timezone);

        $this->events[] = $event;

        return $event;
    }
}
