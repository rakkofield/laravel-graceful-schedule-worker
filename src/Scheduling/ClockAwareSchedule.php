<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Application;
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

    /** @var TimezoneResolver */
    private $timezoneResolver;

    /**
     * @param ClockInterface $clock
     * @param string $defaultDispatcherType
     * @param \DateTimeZone|string|null $timezone
     * @param TimezoneResolver $timezoneResolver
     */
    public function __construct(
        ClockInterface $clock,
        string $defaultDispatcherType,
        $timezone,
        TimezoneResolver $timezoneResolver
    ) {
        parent::__construct($timezone);
        $this->clock = $clock;
        $this->eventClock = new FreezableClock($clock);
        $this->defaultDispatcherType = $defaultDispatcherType;
        $this->timezoneResolver = $timezoneResolver;
    }

    /**
     * Add a new Artisan command event to the schedule.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return ClockAwareEvent
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     *     Container::getInstance and Application::formatCommandString are framework APIs
     */
    public function command($command, array $parameters = [])
    {
        if (class_exists($command)) {
            /** @var \Illuminate\Console\Command $resolved */
            $resolved = Container::getInstance()->make($command);
            $command = $resolved->getName();
        }

        $rawCommand = (string) $command;
        if (count($parameters)) {
            $rawCommand .= ' ' . $this->compileParameters($parameters);
        }

        $event = $this->exec(
            Application::formatCommandString((string) $command),
            $parameters
        );

        $event->setRawCommand($rawCommand);

        return $event;
    }

    /**
     * Add a new command event to the schedule.
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

        $event = new ClockAwareEvent(
            $this->eventMutex,
            $command,
            $this->eventClock,
            $this->defaultDispatcherType,
            $this->timezone,
            $this->timezoneResolver
        );

        $this->events[] = $event;

        return $event;
    }

    /**
     * {@inheritdoc}
     *
     * @return ClockAwareEvent[]
     */
    public function events()
    {
        /** @var ClockAwareEvent[] $events */
        $events = parent::events();
        return $events;
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
