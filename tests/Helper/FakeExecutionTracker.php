<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * ExecutionTrackerInterface の Fake 実装
 */
class FakeExecutionTracker implements ExecutionTrackerInterface
{
    /**
     * @var array<string, Carbon>
     */
    private $executed = [];

    /**
     * @var array<string, bool>
     */
    private $locks = [];

    /**
     * @var array<string, Carbon|null>
     */
    private $recoverableResults = [];

    /**
     * @var array<string, bool>
     */
    private $lockResults = [];

    /**
     * {@inheritdoc}
     */
    public function markExecuted(Event $event, Carbon $dueAt): void
    {
        $this->executed[$event->mutexName()] = $dueAt;
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(Event $event, Carbon $now): ?Carbon
    {
        return $this->recoverableResults[$event->mutexName()] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, Carbon $dueAt): bool
    {
        $key = $event->mutexName() . ':' . $dueAt->timestamp;

        // 特定のロック結果が設定されている場合はそれを返す
        if (isset($this->lockResults[$key])) {
            return $this->lockResults[$key];
        }

        if (isset($this->locks[$key])) {
            return false;
        }

        $this->locks[$key] = true;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(Event $event, Carbon $dueAt): void
    {
        $key = $event->mutexName() . ':' . $dueAt->timestamp;
        unset($this->locks[$key]);
    }

    /**
     * テスト用: リカバリ判定結果を設定
     *
     * @param string $mutexName
     * @param Carbon|null $missedDue リカバリすべき場合は missedDue、そうでなければ null
     */
    public function setRecoverableResult(string $mutexName, ?Carbon $missedDue): void
    {
        $this->recoverableResults[$mutexName] = $missedDue;
    }

    /**
     * テスト用: ロック取得結果を設定
     *
     * @param string $mutexName
     * @param Carbon $dueAt
     * @param bool $result
     */
    public function setLockResult(string $mutexName, Carbon $dueAt, bool $result): void
    {
        $key = $mutexName . ':' . $dueAt->timestamp;
        $this->lockResults[$key] = $result;
    }

    /**
     * テスト用: 実行記録を取得
     *
     * @return array<string, Carbon>
     */
    public function getExecuted(): array
    {
        return $this->executed;
    }

    /**
     * テスト用: ロック状態を取得
     *
     * @return array<string, bool>
     */
    public function getLocks(): array
    {
        return $this->locks;
    }

    /**
     * テスト用: 実行記録を設定
     *
     * @param string $mutexName
     * @param Carbon $dueAt
     */
    public function setExecuted(string $mutexName, Carbon $dueAt): void
    {
        $this->executed[$mutexName] = $dueAt;
    }

    /**
     * テスト用: リセット
     */
    public function reset(): void
    {
        $this->executed = [];
        $this->locks = [];
        $this->recoverableResults = [];
        $this->lockResults = [];
    }
}
