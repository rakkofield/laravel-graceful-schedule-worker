<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;

/**
 * ExecutionTrackerInterface の Fake 実装
 */
class FakeExecutionTracker implements ExecutionTrackerInterface
{
    /**
     * @var array<string, DateTimeInterface>
     */
    private $executed = [];

    /**
     * @var array<string, bool>
     */
    private $locks = [];

    /**
     * @var array<string, DateTimeInterface|null>
     */
    private $recoverableResults = [];

    /**
     * @var array<string, bool>
     */
    private $lockResults = [];

    /**
     * {@inheritdoc}
     */
    public function markExecuted(Event $event, DateTimeInterface $dueAt): void
    {
        $this->executed[$event->mutexName()] = $dueAt;
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(Event $event, DateTimeInterface $now): ?DateTimeInterface
    {
        return $this->recoverableResults[$event->mutexName()] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, DateTimeInterface $dueAt): bool
    {
        $key = $event->mutexName() . ':' . $dueAt->getTimestamp();

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
    public function releaseLock(Event $event, DateTimeInterface $dueAt): void
    {
        $key = $event->mutexName() . ':' . $dueAt->getTimestamp();
        unset($this->locks[$key]);
    }

    /**
     * テスト用: リカバリ判定結果を設定
     *
     * @param string $mutexName
     * @param DateTimeInterface|null $missedDue リカバリすべき場合は missedDue、そうでなければ null
     */
    public function setRecoverableResult(string $mutexName, ?DateTimeInterface $missedDue): void
    {
        $this->recoverableResults[$mutexName] = $missedDue;
    }

    /**
     * テスト用: ロック取得結果を設定
     *
     * @param string $mutexName
     * @param DateTimeInterface $dueAt
     * @param bool $result
     */
    public function setLockResult(string $mutexName, DateTimeInterface $dueAt, bool $result): void
    {
        $key = $mutexName . ':' . $dueAt->getTimestamp();
        $this->lockResults[$key] = $result;
    }

    /**
     * テスト用: 実行記録を取得
     *
     * @return array<string, DateTimeInterface>
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
     * @param DateTimeInterface $dueAt
     */
    public function setExecuted(string $mutexName, DateTimeInterface $dueAt): void
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
