<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use InvalidArgumentException;

/**
 * Owns the derivation of the Step Functions Task timeout, together with the
 * settings it depends on: the fallback lock lifetime, the margin reserved for
 * releasing the lock, and the floor below which a derived timeout is useless.
 *
 * The timeout normally tracks the *remaining* lock lifetime, because a task that
 * outlives its lock lets the next occurrence acquire the lock and start a second,
 * overlapping ECS task. Anchoring on the remaining lifetime means a late dispatch
 * shortens the timeout by exactly the delay instead of overshooting the expiry.
 *
 * Below the floor that tracking stops, deliberately: see deriveTimeoutSeconds().
 */
class StepFunctionsTimeoutSettings
{
    /** Default fallback lock lifetime; kept in sync with config/graceful-scheduler.php. */
    public const DEFAULT_LOCK_TTL = 3600;

    /** Default lock-release margin; kept in sync with config/graceful-scheduler.php. */
    public const DEFAULT_LOCK_RELEASE_BUFFER = 60;

    /** Default floor for a derived timeout; kept in sync with config/graceful-scheduler.php. */
    public const DEFAULT_MIN_TASK_TIMEOUT = 60;

    /** @var int */
    private $lockTtlSeconds;

    /** @var int */
    private $lockReleaseBufferSeconds;

    /** @var int */
    private $minTaskTimeoutSeconds;

    /**
     * @param int $lockTtlSeconds Fallback lock lifetime for events without withoutOverlapping
     * @param int $lockReleaseBufferSeconds Seconds reserved between the Task timeout and the lock expiry
     * @param int $minTaskTimeoutSeconds Floor below which a derived timeout is treated as unusable
     * @throws InvalidArgumentException if the values cannot bound a task run
     */
    public function __construct(
        int $lockTtlSeconds,
        int $lockReleaseBufferSeconds,
        int $minTaskTimeoutSeconds = self::DEFAULT_MIN_TASK_TIMEOUT
    ) {
        self::requireAtLeastOneSecond('lock_ttl', $lockTtlSeconds);
        // Zero would leave the timeout landing exactly on the lock expiry, with nothing
        // left for the release path the state machine runs after a caught timeout.
        self::requireAtLeastOneSecond('lock_release_buffer', $lockReleaseBufferSeconds);
        self::requireAtLeastOneSecond('min_task_timeout', $minTaskTimeoutSeconds);

        if ($lockTtlSeconds <= $lockReleaseBufferSeconds) {
            throw new InvalidArgumentException(sprintf(
                'graceful-scheduler.stepfunctions.lock_ttl (%d) must be greater than '
                . 'lock_release_buffer (%d); otherwise no task that falls back to the configured '
                . 'lock_ttl can be given any run time at all.',
                $lockTtlSeconds,
                $lockReleaseBufferSeconds
            ));
        }

        $budget = $lockTtlSeconds - $lockReleaseBufferSeconds;
        if ($minTaskTimeoutSeconds > $budget) {
            throw new InvalidArgumentException(sprintf(
                'graceful-scheduler.stepfunctions.min_task_timeout (%d) must not exceed '
                . 'lock_ttl - lock_release_buffer (%d - %d = %d); otherwise the floor would '
                . 'replace every derived timeout on the configured path.',
                $minTaskTimeoutSeconds,
                $lockTtlSeconds,
                $lockReleaseBufferSeconds,
                $budget
            ));
        }

        $this->lockTtlSeconds = $lockTtlSeconds;
        $this->lockReleaseBufferSeconds = $lockReleaseBufferSeconds;
        $this->minTaskTimeoutSeconds = $minTaskTimeoutSeconds;
    }

    /**
     * Derive the Task timeout for one dispatch.
     *
     * Two regimes:
     *
     * 1. The lock still has room for the run: return the remaining lock lifetime minus
     *    the release margin, so the task cannot outlive the lock that protects it.
     * 2. Less than the floor is left: the lock cannot bound this run whatever we return,
     *    so exclusivity is already forfeit. Clamping to a second would only kill the task
     *    (a recovery dispatch hours after dueAt lands here every time, as does an event
     *    whose withoutOverlapping window is barely wider than the release margin). Fall
     *    back to the budget the event itself declared, and never below the floor.
     *
     * Regime 2 can therefore return a timeout that outlasts expiresAt. That is a
     * deliberate trade: the lock is already unable to exclude a concurrent run, and a
     * task killed after one second cannot do the job it was dispatched for.
     *
     * @param int $expiresAt Unix timestamp at which the lock expires (dueAt + $lockTtlSeconds)
     * @param int $dispatchedAt Unix timestamp at dispatch time
     * @param int $lockTtlSeconds Lock lifetime this event resolved to (may differ from the configured fallback)
     * @return int Always positive, as Step Functions rejects a non-positive TimeoutSeconds
     */
    public function deriveTimeoutSeconds(int $expiresAt, int $dispatchedAt, int $lockTtlSeconds): int
    {
        $usable = $expiresAt - $dispatchedAt - $this->lockReleaseBufferSeconds;
        if ($usable >= $this->minTaskTimeoutSeconds) {
            return $usable;
        }

        return max($this->minTaskTimeoutSeconds, $lockTtlSeconds - $this->lockReleaseBufferSeconds);
    }

    /**
     * @return int
     */
    public function getLockTtlSeconds(): int
    {
        return $this->lockTtlSeconds;
    }

    /**
     * @return int
     */
    public function getLockReleaseBufferSeconds(): int
    {
        return $this->lockReleaseBufferSeconds;
    }

    /**
     * @return int
     */
    public function getMinTaskTimeoutSeconds(): int
    {
        return $this->minTaskTimeoutSeconds;
    }

    /**
     * @param string $configKey
     * @param int $value
     * @return void
     */
    private static function requireAtLeastOneSecond(string $configKey, int $value): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf(
                'graceful-scheduler.stepfunctions.%s must be at least 1 second, got %d.',
                $configKey,
                $value
            ));
        }
    }
}
