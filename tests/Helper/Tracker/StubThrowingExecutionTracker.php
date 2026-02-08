<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use Exception;
use Illuminate\Console\Scheduling\Event;

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
    public function markExecuted(Event $event, DateTimeInterface $dueAt): void
    {
        throw $this->exception;
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(Event $event, DateTimeInterface $now): ?DateTimeInterface
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, DateTimeInterface $dueAt): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(Event $event, DateTimeInterface $dueAt): void
    {
    }
}
