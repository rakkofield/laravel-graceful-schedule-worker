<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use Exception;
use Illuminate\Console\Scheduling\Event;

/**
 * markExecuted() で例外をスローする ExecutionTrackerInterface の Stub
 */
class StubThrowingExecutionTracker implements ExecutionTrackerInterface
{
    /** @var Exception */
    private $exception;

    /**
     * @param Exception $exception markExecuted() でスローする例外
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
