<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;

/**
 * Fake implementation of ExecutionTrackerInterface
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
     * @var array<string, \Exception>
     */
    private $recoverableExceptions = [];

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
        if (isset($this->recoverableExceptions[$event->mutexName()])) {
            throw $this->recoverableExceptions[$event->mutexName()];
        }
        return $this->recoverableResults[$event->mutexName()] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, DateTimeInterface $dueAt): bool
    {
        $key = $event->mutexName() . ':' . $dueAt->getTimestamp();

        // Return the configured result if a specific lock result has been set
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
     * Test helper: set recovery determination result
     *
     * @param string $mutexName
     * @param DateTimeInterface|null $missedDue The missed due time if recovery is needed, null otherwise
     */
    public function setRecoverableResult(string $mutexName, ?DateTimeInterface $missedDue): void
    {
        $this->recoverableResults[$mutexName] = $missedDue;
    }

    /**
     * Test helper: set lock acquisition result
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
     * Test helper: get execution records
     *
     * @return array<string, DateTimeInterface>
     */
    public function getExecuted(): array
    {
        return $this->executed;
    }

    /**
     * Test helper: get lock state
     *
     * @return array<string, bool>
     */
    public function getLocks(): array
    {
        return $this->locks;
    }

    /**
     * Test helper: set execution record
     *
     * @param string $mutexName
     * @param DateTimeInterface $dueAt
     */
    public function setExecuted(string $mutexName, DateTimeInterface $dueAt): void
    {
        $this->executed[$mutexName] = $dueAt;
    }

    /**
     * Test helper: set exception for getMissedDueIfRecoverable
     *
     * @param string $mutexName
     * @param \Exception $exception
     */
    public function setRecoverableException(string $mutexName, \Exception $exception): void
    {
        $this->recoverableExceptions[$mutexName] = $exception;
    }

    /**
     * Test helper: reset
     */
    public function reset(): void
    {
        $this->executed = [];
        $this->locks = [];
        $this->recoverableResults = [];
        $this->recoverableExceptions = [];
        $this->lockResults = [];
    }
}
