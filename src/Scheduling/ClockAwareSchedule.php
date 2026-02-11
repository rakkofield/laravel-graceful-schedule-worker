<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FreezableClock;

class ClockAwareSchedule extends Schedule
{
    /** @var ClockInterface The original clock injected via constructor */
    protected $clock;

    /** @var FreezableClock Freezable clock wrapper shared across all ClockAwareEvents */
    private $eventClock;

    /** @var string */
    protected $defaultDispatcherType;

    /** @var bool When true, exec() generates the parent's Event */
    private $nativeEventMode = false;

    /**
     * @param ClockInterface $clock
     * @param \DateTimeZone|string|null $timezone
     * @param string $defaultDispatcherType
     */
    public function __construct(ClockInterface $clock, $timezone = null, string $defaultDispatcherType = 'local')
    {
        parent::__construct($timezone);
        $this->clock = $clock;
        $this->eventClock = new FreezableClock($clock);
        $this->defaultDispatcherType = $defaultDispatcherType;
    }

    /**
     * Execute a callback in native events mode.
     *
     * command()/exec() calls within the callback will generate the parent's Event.
     * Since the standard Laravel Event is used instead of ClockAwareEvent,
     * the clock behavior is unaffected.
     *
     * @param callable $callback
     * @return void
     */
    public function withNativeEvents(callable $callback)
    {
        $this->nativeEventMode = true;
        try {
            $callback();
        } finally {
            $this->nativeEventMode = false;
        }
    }

    /**
     * Add a new Artisan command event to the schedule.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return ClockAwareEvent|Event
     */
    public function command($command, array $parameters = [])
    {
        if (class_exists($command)) {
            /** @var \Illuminate\Console\Command $resolved */
            $resolved = Container::getInstance()->make($command);
            $command = $resolved->getName();
        }

        return $this->exec(
            Application::formatCommandString((string) $command),
            $parameters
        );
    }

    /**
     * Add a new command event to the schedule.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return ClockAwareEvent|Event
     */
    public function exec($command, array $parameters = [])
    {
        if ($this->nativeEventMode) {
            return parent::exec($command, $parameters);
        }

        if (count($parameters)) {
            $command .= ' ' . $this->compileParameters($parameters);
        }

        $event = new ClockAwareEvent(
            $this->eventMutex,
            $command,
            $this->eventClock,
            $this->timezone,
            $this->defaultDispatcherType
        );

        $this->events[] = $event;

        return $event;
    }

    /**
     * Execute a callback with the clock frozen at the specified time.
     *
     * Called by the Orchestrator as an entry point to evaluate
     * dueEvents() + filtersPass() at the same frozen time.
     *
     * @param DateTimeImmutable $time The reference time for evaluation
     * @param callable $callback The callback to execute
     * @return mixed
     */
    public function evaluateAt(DateTimeImmutable $time, callable $callback)
    {
        return $this->eventClock->withFrozenTime($time, $callback);
    }
}
