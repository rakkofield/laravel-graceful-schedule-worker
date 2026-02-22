<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Cron\CronExpression;
use Cron\FieldFactory;
use DateInterval;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

class ClockAwareEvent extends Event
{
    /**
     * @var ClockInterface
     */
    protected $clock;

    /**
     * @var DateInterval|null
     */
    protected $gracePeriod = null;

    /**
     * @var bool
     */
    protected $recoverable = false;

    /**
     * @var string
     */
    protected $dispatcherType;

    /** @var string|null */
    protected $rawCommand = null;

    /** @var ClockAwareTimeFilter */
    private $timeFilter;

    /** @var TimezoneResolver */
    private $timezoneResolver;

    /**
     * @param EventMutex $mutex
     * @param string $command
     * @param ClockInterface $clock
     * @param string $defaultDispatcherType
     * @param \DateTimeZone|string|null $timezone
     * @param TimezoneResolver $timezoneResolver
     * @throws InvalidArgumentException If timezone string is invalid
     */
    public function __construct(
        EventMutex $mutex,
        $command,
        ClockInterface $clock,
        string $defaultDispatcherType,
        $timezone,
        TimezoneResolver $timezoneResolver
    ) {
        $resolvedTz = $timezoneResolver->resolve($timezone);
        parent::__construct($mutex, $command, $resolvedTz);
        $this->clock = $clock;
        $this->dispatcherType = $defaultDispatcherType;
        $this->timezoneResolver = $timezoneResolver;
        $this->timeFilter = new ClockAwareTimeFilter($clock, $resolvedTz);
    }

    /**
     * @return \DateTimeZone|null
     */
    public function getResolvedTimezone(): ?\DateTimeZone
    {
        if ($this->timezone instanceof \DateTimeZone) {
            return $this->timezone;
        }
        if ($this->timezone !== null) {
            return $this->timezoneResolver->resolve($this->timezone);
        }
        return null;
    }

    /**
     * @param \DateTimeZone|string $timezone
     * @return $this
     * @throws InvalidArgumentException
     */
    public function timezone($timezone)
    {
        /** @var \DateTimeZone $resolved TimezoneResolver::resolve never returns null for non-null input */
        $resolved = $this->timezoneResolver->resolve($timezone);
        $this->timeFilter = new ClockAwareTimeFilter($this->clock, $resolved);
        return parent::timezone($resolved);
    }

    /**
     * @param int|null $minutes Grace period in minutes. null means unlimited
     * @return $this
     */
    public function withGracePeriod($minutes = null)
    {
        $this->recoverable = true;
        $this->gracePeriod = null;

        if ($minutes !== null && $minutes > 0) {
            $this->gracePeriod = new DateInterval("PT{$minutes}M");
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function enableRecovery()
    {
        $this->recoverable = true;
        $this->gracePeriod = null;
        return $this;
    }

    /**
     * @param string $type 'local' or 'stepfunctions'
     * @return $this
     */
    public function dispatchVia($type)
    {
        $this->dispatcherType = $type;
        return $this;
    }

    /**
     * @return string
     */
    public function getDispatcherType(): string
    {
        return $this->dispatcherType;
    }

    /**
     * @return bool
     */
    public function isRecoverable()
    {
        return $this->recoverable;
    }

    /**
     * @return DateInterval|null
     */
    public function getGracePeriod()
    {
        return $this->gracePeriod;
    }

    /**
     * @return string|null
     */
    public function getRawCommand(): ?string
    {
        return $this->rawCommand;
    }

    /**
     * @param string $rawCommand
     * @return void
     */
    public function setRawCommand(string $rawCommand): void
    {
        $this->rawCommand = $rawCommand;
    }

    /**
     * @return CronExpression
     */
    public function createCronExpression(): CronExpression
    {
        return new CronExpression($this->expression, new FieldFactory());
    }

    /**
     * @return bool
     */
    protected function expressionPasses()
    {
        $date = $this->timeFilter->nowWithTimezone();

        return $this->createCronExpression()->isDue($date->format('Y-m-d H:i:s'));
    }

    /**
     * @param string $startTime
     * @param string $endTime
     * @return $this
     */
    public function between($startTime, $endTime)
    {
        return $this->when($this->timeFilter->createInterval($startTime, $endTime));
    }

    /**
     * @param string $startTime
     * @param string $endTime
     * @return $this
     */
    public function unlessBetween($startTime, $endTime)
    {
        return $this->skip($this->timeFilter->createInterval($startTime, $endTime));
    }

    /**
     * Call after callbacks with exit code (backward-compatible).
     *
     * Provides compatibility with Laravel 6 which does not have
     * callAfterCallbacksWithExitCode on the Event class.
     *
     * @param Container $container
     * @param int $exitCode
     * @return void
     */
    public function callAfterCallbacksWithExitCode(Container $container, $exitCode)
    {
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists(Event::class, 'callAfterCallbacksWithExitCode')) {
            parent::callAfterCallbacksWithExitCode($container, $exitCode);
            return;
        }

        // Laravel 6 fallback: set exitCode and call afterCallbacks
        $this->exitCode = (int) $exitCode;
        parent::callAfterCallbacks($container);
    }

    /**
     * Build the command string for execution via Symfony Process.
     *
     * Delegates to ProcessCommandBuilder which extends Laravel's CommandBuilder,
     * using exec to replace the shell process so SIGTERM is delivered directly.
     *
     * @return string
     */
    public function buildProcessCommand()
    {
        return (new ProcessCommandBuilder())->buildCommand($this);
    }
}
