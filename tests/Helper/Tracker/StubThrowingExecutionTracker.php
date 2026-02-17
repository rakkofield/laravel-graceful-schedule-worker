<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use Exception;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * ExecutionTrackerInterface Stub that throws an exception on markExecuted()
 */
class StubThrowingExecutionTracker implements ExecutionTrackerInterface
{
    /** @var Exception */
    private $exception;

    /**
     * @param Exception $exception Exception to throw in markExecuted()
     */
    public function __construct(Exception $exception)
    {
        $this->exception = $exception;
    }

    /**
     * {@inheritdoc}
     */
    public function markExecuted(ClockAwareEvent $event, DateTimeInterface $dueAt): void
    {
        throw $this->exception;
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(ClockAwareEvent $event, DateTimeInterface $now): ?DateTimeInterface
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(ClockAwareEvent $event, DateTimeInterface $dueAt): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(ClockAwareEvent $event, DateTimeInterface $dueAt): void
    {
    }
}
