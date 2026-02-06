<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * ExecutionTrackerInterface の Fake 実装（キャッシュ接続失敗をシミュレート）
 *
 * すべてのメソッドで指定された例外をスローします。
 */
class ThrowingFakeExecutionTracker implements ExecutionTrackerInterface
{
    /** @var \Exception */
    private $exception;

    /**
     * @param \Exception $exception スローする例外
     */
    public function __construct(\Exception $exception)
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
        throw $this->exception;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, DateTimeInterface $dueAt): bool
    {
        throw $this->exception;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(Event $event, DateTimeInterface $dueAt): void
    {
        throw $this->exception;
    }
}
