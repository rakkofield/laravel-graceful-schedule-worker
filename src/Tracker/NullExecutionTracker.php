<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;

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
    public function markExecuted(Event $event, DateTimeInterface $dueAt): void
    {
        // no-op
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(Event $event, DateTimeInterface $now): ?DateTimeInterface
    {
        return null; // No missed executions
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, DateTimeInterface $dueAt): bool
    {
        return true; // Always succeeds
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(Event $event, DateTimeInterface $dueAt): void
    {
        // no-op
    }
}
