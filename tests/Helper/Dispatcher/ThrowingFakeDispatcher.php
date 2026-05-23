<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * FakeDispatcher for testing that throws exceptions on cleanup()/stopAll()
 */
class ThrowingFakeDispatcher implements ScheduleDispatcherInterface
{
    /** @var DispatchResultInterface */
    private $resultToReturn;

    /** @var \Throwable|null */
    private $dispatchException;

    /** @var \Throwable|null */
    private $cleanupException;

    /** @var \Throwable|null */
    private $stopAllException;

    /** @var int */
    private $dispatchCount = 0;

    /** @var int */
    private $cleanupCallCount = 0;

    /** @var int */
    private $stopAllCallCount = 0;

    /**
     * @param DispatchResultInterface $resultToReturn
     */
    public function __construct(DispatchResultInterface $resultToReturn)
    {
        $this->resultToReturn = $resultToReturn;
    }

    /**
     * Dispatch an event.
     *
     * @param ClockAwareEvent $event
     * @param DateTimeInterface $dueAt
     * @param \DateTimeImmutable $dispatchedAt
     * @return DispatchResultInterface
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt,
        \DateTimeImmutable $dispatchedAt
    ): DispatchResultInterface {
        $this->dispatchCount++;
        if ($this->dispatchException !== null) {
            throw $this->dispatchException;
        }
        return $this->resultToReturn;
    }

    /**
     * Configure dispatchEvent to throw an exception.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function willThrowOnDispatch(\Throwable $exception): void
    {
        $this->dispatchException = $exception;
    }

    /**
     * Configure cleanup to throw an exception.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function willThrowOnCleanup(\Throwable $exception): void
    {
        $this->cleanupException = $exception;
    }

    /**
     * Configure stopAll to throw an exception.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function willThrowOnStopAll(\Throwable $exception): void
    {
        $this->stopAllException = $exception;
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        $this->cleanupCallCount++;
        if ($this->cleanupException !== null) {
            throw $this->cleanupException;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        $this->stopAllCallCount++;
        if ($this->stopAllException !== null) {
            throw $this->stopAllException;
        }
    }

    /**
     * Get the number of times dispatchEvent() was called.
     *
     * @return int
     */
    public function getDispatchCount(): int
    {
        return $this->dispatchCount;
    }

    /**
     * Get the number of times cleanup() was called.
     *
     * @return int
     */
    public function getCleanupCallCount(): int
    {
        return $this->cleanupCallCount;
    }

    /**
     * Get the number of times stopAll() was called.
     *
     * @return int
     */
    public function getStopAllCallCount(): int
    {
        return $this->stopAllCallCount;
    }
}
