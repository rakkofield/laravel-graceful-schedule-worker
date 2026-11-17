<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
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

    /** @var int|null Number of events kept by stopAcceptingEvents(); null until it is called */
    private $acceptedEventCount;

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

        $rawCommand = $this->buildRawCommandArray((string) $command, $parameters);

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

        if ($this->acceptedEventCount !== null) {
            $events = array_slice($events, 0, $this->acceptedEventCount);
        }

        return $events;
    }

    /**
     * {@inheritdoc}
     *
     * Overridden because the parent reads the events property directly in most
     * Laravel versions (e.g. 13.0), which would include the events ignored by
     * stopAcceptingEvents().
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return Collection<int, ClockAwareEvent>
     */
    public function dueEvents($app)
    {
        return (new Collection($this->events()))->filter(function (Event $event) use ($app) {
            return $event->isDue($app);
        });
    }

    /**
     * Ignore the events added after this call.
     *
     * Called once gracefulSchedule() has defined the schedule. On Laravel 13,
     * withSchedule() in bootstrap/app.php registers its callback with
     * afterResolving(Schedule::class), and the container also runs it for this
     * subclass. Without this, the tasks defined there would also be run by the
     * worker. They are left to schedule:run, as on Laravel 6 - 12.
     *
     * @return void
     */
    public function stopAcceptingEvents(): void
    {
        $this->acceptedEventCount = count($this->events);
    }

    /**
     * Build rawCommand as an array of individual arguments.
     *
     * Unlike compileParameters() which produces a shell-escaped string,
     * this builds an array where each element is a separate argument
     * without shell escaping (unnecessary for array-based command passing).
     *
     * Inline arguments in $command (e.g. 'command:name arg1 arg2') are split on
     * whitespace only. This is not a shell lexer: quoted values or
     * backslash-escaped spaces are not honored. Use the $parameters array to
     * pass values containing whitespace.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return string[]
     * @throws \InvalidArgumentException When $command is empty or whitespace-only.
     */
    private function buildRawCommandArray(string $command, array $parameters): array
    {
        $result = ClockAwareEvent::splitCommandString($command);
        if ($result === []) {
            throw new \InvalidArgumentException('Command must not be empty.');
        }

        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                /** @var array<int, string|int> $value */
                $result = array_merge($result, $this->compileArrayParameter($key, $value));
                continue;
            }

            $stringValue = (string) (is_scalar($value) ? $value : '');

            if (is_numeric($key)) {
                $result[] = $stringValue;
                continue;
            }

            $result[] = $key . '=' . $stringValue;
        }

        return $result;
    }

    /**
     * Compile an array parameter into individual argument elements.
     *
     * @param string|int $key
     * @param array<int, string|int> $values
     * @return string[]
     */
    private function compileArrayParameter($key, array $values): array
    {
        $result = [];

        if (is_string($key) && strncmp($key, '--', 2) === 0) {
            foreach ($values as $v) {
                $result[] = $key . '=' . $v;
            }
            return $result;
        }

        if (is_string($key) && isset($key[0]) && $key[0] === '-') {
            foreach ($values as $v) {
                $result[] = $key;
                $result[] = (string) $v;
            }
            return $result;
        }

        foreach ($values as $v) {
            $result[] = (string) $v;
        }

        return $result;
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
