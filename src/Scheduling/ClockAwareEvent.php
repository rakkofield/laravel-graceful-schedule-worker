<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Closure;
use Cron\CronExpression;
use DateInterval;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
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
     * @var string|null
     */
    protected $dispatcherType = null;

    /**
     * @param EventMutex $mutex
     * @param string $command
     * @param ClockInterface $clock
     * @param \DateTimeZone|string|null $timezone
     */
    public function __construct(EventMutex $mutex, $command, ClockInterface $clock, $timezone = null)
    {
        parent::__construct($mutex, $command, $timezone);
        $this->clock = $clock;
    }

    /**
     * Enable recovery (set grace period).
     *
     * @param int|null $minutes Grace period in minutes. null means unlimited
     * @return $this
     */
    public function withGracePeriod($minutes = null)
    {
        $this->recoverable = true;

        if ($minutes !== null && $minutes > 0) {
            $this->gracePeriod = new DateInterval("PT{$minutes}M");
        } else {
            $this->gracePeriod = null;
        }

        return $this;
    }

    /**
     * Enable recovery (no grace period).
     *
     * @return $this
     */
    public function enableRecovery()
    {
        $this->recoverable = true;
        $this->gracePeriod = null;
        return $this;
    }

    /**
     * Specify the dispatcher type.
     *
     * @param string $type 'local' or 'stepfunctions'
     * @return $this
     */
    public function dispatchVia($type)
    {
        $this->dispatcherType = $type;
        return $this;
    }

    /**
     * Get the specified dispatcher type.
     *
     * @return string|null Dispatcher type (null if not specified)
     */
    public function getDispatcherType()
    {
        return $this->dispatcherType;
    }

    /**
     * Check whether recovery is enabled.
     *
     * @return bool
     */
    public function isRecoverable()
    {
        return $this->recoverable;
    }

    /**
     * Get the grace period.
     *
     * @return DateInterval|null
     */
    public function getGracePeriod()
    {
        return $this->gracePeriod;
    }

    /**
     * @return bool
     */
    protected function expressionPasses()
    {
        $date = $this->clock->now();

        if ($this->timezone) {
            $tz = $this->timezone instanceof \DateTimeZone ? $this->timezone : new \DateTimeZone($this->timezone);
            $date = $date->setTimezone($tz);
        }

        return (new CronExpression($this->expression))->isDue($date->format('Y-m-d H:i:s'));
    }

    /**
     * @param string $startTime
     * @param string $endTime
     * @return $this
     */
    public function between($startTime, $endTime)
    {
        return $this->when($this->clockAwareTimeInterval($startTime, $endTime));
    }

    /**
     * @param string $startTime
     * @param string $endTime
     * @return $this
     */
    public function unlessBetween($startTime, $endTime)
    {
        return $this->skip($this->clockAwareTimeInterval($startTime, $endTime));
    }

    /**
     * Generate a closure that checks the time interval using the clock.
     *
     * The parent's inTimeInterval() is private and eagerly evaluates Carbon::now() at definition time,
     * so in long-running workers the time gets fixed at startup.
     * This implementation lazily evaluates clock->now() inside the closure to use the correct time each time.
     *
     * @param string $startTime
     * @param string $endTime
     * @return Closure
     */
    private function clockAwareTimeInterval($startTime, $endTime)
    {
        return function () use ($startTime, $endTime) {
            $now = $this->clock->now();

            if ($this->timezone) {
                $tz = $this->timezone instanceof \DateTimeZone ? $this->timezone : new \DateTimeZone($this->timezone);
                $now = $now->setTimezone($tz);
            }

            $start = $this->applyTimeString($now, $startTime);
            $end = $this->applyTimeString($now, $endTime);

            if ($end < $start) {
                if ($start > $now) {
                    $start = $start->modify('-1 day');
                } else {
                    $end = $end->modify('+1 day');
                }
            }

            return $now >= $start && $now <= $end;
        };
    }

    /**
     * @param \DateTimeImmutable $date
     * @param string $timeString "HH:MM" or "HH:MM:SS"
     * @return \DateTimeImmutable
     */
    private function applyTimeString(\DateTimeImmutable $date, string $timeString): \DateTimeImmutable
    {
        $parts = explode(':', $timeString);
        return $date->setTime((int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));
    }

    /**
     * Build the command string for execution via Symfony Process.
     *
     * Strips the trailing & that buildCommand() appends and returns the result.
     * The & is unnecessary since Process::start() provides async execution,
     * and its presence would cause SIGTERM to propagate to the entire process group on proc_terminate.
     *
     * Assumes runInBackground = true when called.
     *
     * @return string
     */
    public function buildProcessCommand()
    {
        $command = $this->buildCommand();
        return preg_replace('/\s+&\s*$/', '', $command) ?? $command;
    }
}
