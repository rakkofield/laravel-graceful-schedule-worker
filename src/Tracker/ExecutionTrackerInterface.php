<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;

/**
 * Interface defining the contract for execution tracking.
 *
 * Records task executions and detects missed executions to support
 * at-least-once semantics.
 */
interface ExecutionTrackerInterface
{
    /**
     * Record a task execution.
     *
     * @param Event $event The executed event
     * @param DateTimeInterface $dueAt Scheduled due time
     */
    public function markExecuted(Event $event, DateTimeInterface $dueAt): void;

    /**
     * Return the scheduled due time if there is a recoverable missed execution.
     *
     * Returns missedDue when all of the following conditions are met:
     * - A scheduled due exists after the last executed due time (missed execution detected)
     * - Within the grace period (for ClockAwareEvent)
     *
     * Returns null on first execution (no execution record).
     * Throws an exception if the cron expression is invalid.
     *
     * @param Event $event The event to check
     * @param DateTimeInterface $now Current time
     * @return DateTimeInterface|null missedDue if recovery is needed, null otherwise
     * @throws \InvalidArgumentException If the cron expression is invalid
     */
    public function getMissedDueIfRecoverable(Event $event, DateTimeInterface $now): ?DateTimeInterface;

    /**
     * Acquire a lock for the specified due time.
     *
     * Acquires an exclusive lock to prevent multiple workers from executing the same task.
     *
     * @param Event $event Target event
     * @param DateTimeInterface $dueAt Scheduled due time
     * @return bool true if lock acquisition succeeded
     */
    public function acquireLock(Event $event, DateTimeInterface $dueAt): bool;

    /**
     * Release the lock for the specified due time.
     *
     * Note: Locks are designed to auto-expire via TTL, so calling this method
     * is not required in normal operation. Use it only when early release is needed.
     *
     * @param Event $event Target event
     * @param DateTimeInterface $dueAt Scheduled due time
     */
    public function releaseLock(Event $event, DateTimeInterface $dueAt): void;
}
