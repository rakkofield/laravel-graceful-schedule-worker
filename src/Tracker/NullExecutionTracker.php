<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;

/**
 * NullObject パターンによる ExecutionTracker 実装
 *
 * tracker が無効な場合に使用します。
 * すべてのメソッドが何もしないか、安全なデフォルト値を返します。
 */
class NullExecutionTracker implements ExecutionTrackerInterface
{
    /**
     * {@inheritdoc}
     */
    public function markExecuted(Event $event, Carbon $dueAt): void
    {
        // 何もしない
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(Event $event, Carbon $now): ?Carbon
    {
        return null; // 取りこぼしなし
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, Carbon $dueAt): bool
    {
        return true; // 常に成功
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(Event $event, Carbon $dueAt): void
    {
        // 何もしない
    }
}
