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

    /** @var string[]|null */
    protected $rawCommand = null;

    /** @var int|null */
    protected $taskTimeoutSeconds = null;

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
     * Declare how long this job may run, in seconds.
     *
     * Only the Step Functions dispatcher consumes it: the value is carried in the
     * payload as `timeoutSeconds` and applies once the state machine reads it through
     * `TimeoutSecondsPath`. Local dispatch ignores it.
     *
     * The declared value can only *narrow* the window the lock protects, never widen it,
     * because a task outliving its lock lets the next occurrence start a duplicate. A
     * value that does not fit inside the withoutOverlapping() window is a contradiction
     * and is rejected here rather than silently truncated at dispatch time.
     *
     * @param int $seconds
     * @return $this
     * @throws InvalidArgumentException If not positive, or wider than the lock lifetime
     */
    public function timeoutAfter($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds < 1) {
            throw new InvalidArgumentException(sprintf(
                'timeoutAfter() expects at least 1 second, got %d.',
                $seconds
            ));
        }

        $this->assertTimeoutFitsLockLifetime($seconds, $this->withoutOverlapping ? $this->expiresAt : null);
        $this->taskTimeoutSeconds = $seconds;

        return $this;
    }

    /**
     * Overridden only to catch a timeoutAfter() / withoutOverlapping() contradiction
     * regardless of the order the two are chained in.
     *
     * @param int $expiresAt Lock lifetime in minutes
     * @return $this
     * @throws InvalidArgumentException If narrower than an already declared timeoutAfter()
     */
    public function withoutOverlapping($expiresAt = 1440)
    {
        $this->assertTimeoutFitsLockLifetime($this->taskTimeoutSeconds, $expiresAt);

        return parent::withoutOverlapping($expiresAt);
    }

    /**
     * Seconds declared via timeoutAfter(), or null when the value should be derived.
     *
     * @return int|null
     */
    public function getTaskTimeoutSeconds(): ?int
    {
        return $this->taskTimeoutSeconds;
    }

    /**
     * @param int|null $timeoutSeconds
     * @param int|null $lockLifetimeMinutes
     * @return void
     * @throws InvalidArgumentException
     */
    private function assertTimeoutFitsLockLifetime(?int $timeoutSeconds, $lockLifetimeMinutes): void
    {
        if ($timeoutSeconds === null || $lockLifetimeMinutes === null) {
            return;
        }

        $lockLifetimeSeconds = ((int) $lockLifetimeMinutes) * 60;
        if ($timeoutSeconds < $lockLifetimeSeconds) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'timeoutAfter(%d) must be shorter than the withoutOverlapping(%d) lock lifetime (%d seconds); '
            . 'a task outliving its lock lets the next occurrence start a duplicate. '
            . 'Widen withoutOverlapping() instead.',
            $timeoutSeconds,
            (int) $lockLifetimeMinutes,
            $lockLifetimeSeconds
        ));
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
     * @return string[]|null
     */
    public function getRawCommand(): ?array
    {
        return $this->rawCommand;
    }

    /**
     * Get the effective command as an array for this event.
     *
     * Returns rawCommand if set, otherwise falls back to splitting the command property
     * on whitespace via splitCommandString().
     *
     * @return string[]
     */
    public function getEffectiveCommand(): array
    {
        if ($this->rawCommand !== null) {
            return $this->rawCommand;
        }
        $tokens = self::splitCommandString($this->command);
        return $tokens === [] ? [$this->command] : $tokens;
    }

    /**
     * Split a command string into argv-style tokens on whitespace.
     *
     * Leading/trailing whitespace is trimmed and runs of whitespace (spaces,
     * tabs, newlines) collapse to a single separator. Not a shell lexer: quoted
     * or backslash-escaped whitespace is not honored.
     *
     * Returns [] when the input is empty or whitespace-only; callers decide
     * whether to treat that as an error or a no-op fallback.
     *
     * @param string $command
     * @return string[]
     */
    public static function splitCommandString(string $command): array
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', $trimmed);
        return $tokens === false ? [] : $tokens;
    }

    /**
     * @param string[] $rawCommand
     * @return void
     */
    public function setRawCommand(array $rawCommand): void
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
     * Resolve the lock TTL in seconds.
     *
     * When withoutOverlapping is enabled, converts expiresAt (minutes) to seconds.
     * Otherwise returns the given default value as-is.
     *
     * @param int $defaultTtlSeconds Default TTL in seconds
     * @return int
     */
    public function resolveLockTtlSeconds(int $defaultTtlSeconds): int
    {
        if ($this->withoutOverlapping) {
            return $this->expiresAt * 60;
        }

        return $defaultTtlSeconds;
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
