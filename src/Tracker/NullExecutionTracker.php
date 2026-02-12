<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Null Object pattern implementation of ExecutionTracker.
 *
 * Used when the tracker is disabled.
 * All methods are no-ops or return safe default values.
 */
class NullExecutionTracker implements ExecutionTrackerInterface
{
    /**
     * {@inheritdoc}
     */
    public function markExecuted(ClockAwareEvent $event, DateTimeInterface $dueAt): void
    {
        // no-op
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(ClockAwareEvent $event, DateTimeInterface $now): ?DateTimeInterface
    {
        return null; // No missed executions
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(ClockAwareEvent $event, DateTimeInterface $dueAt): bool
    {
        return true; // Always succeeds
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(ClockAwareEvent $event, DateTimeInterface $dueAt): void
    {
        // no-op
    }
}
