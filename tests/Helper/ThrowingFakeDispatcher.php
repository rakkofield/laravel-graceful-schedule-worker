<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;

/**
 * テスト用：cleanup()/stopAll() で例外をスローする FakeDispatcher
 */
class ThrowingFakeDispatcher implements ScheduleDispatcherInterface
{
    /** @var DispatchResultInterface */
    private $resultToReturn;

    /** @var \Exception|null */
    private $cleanupException;

    /** @var \Exception|null */
    private $stopAllException;

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
     * @param Event $event
     * @param Container $container
     * @param DateTimeInterface $dueAt
     * @return DispatchResultInterface
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        return $this->resultToReturn;
    }

    /**
     * Configure cleanup to throw an exception.
     *
     * @param \Exception $exception
     * @return void
     */
    public function willThrowOnCleanup(\Exception $exception): void
    {
        $this->cleanupException = $exception;
    }

    /**
     * Configure stopAll to throw an exception.
     *
     * @param \Exception $exception
     * @return void
     */
    public function willThrowOnStopAll(\Exception $exception): void
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
