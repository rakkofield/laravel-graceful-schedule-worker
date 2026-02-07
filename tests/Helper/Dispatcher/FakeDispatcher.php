<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;

class FakeDispatcher implements ScheduleDispatcherInterface
{
    /** @var DispatchResultInterface */
    private $resultToReturn;

    /** @var array<array{event: Event, container: Container, dueAt: DateTimeInterface}> */
    private $dispatched = [];

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
        $this->dispatched[] = ['event' => $event, 'container' => $container, 'dueAt' => $dueAt];
        return $this->resultToReturn;
    }

    /**
     * Get dispatched events.
     *
     * @return array<array{event: Event, container: Container, dueAt: DateTimeInterface}>
     */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    /**
     * Get dispatch count.
     *
     * @return int
     */
    public function getDispatchCount(): int
    {
        return count($this->dispatched);
    }

    /**
     * Set the result to return on next dispatch.
     *
     * @param DispatchResultInterface $result
     * @return void
     */
    public function setResult(DispatchResultInterface $result): void
    {
        $this->resultToReturn = $result;
    }

    /**
     * Reset all state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->dispatched = [];
        $this->cleanupCallCount = 0;
        $this->stopAllCallCount = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        $this->cleanupCallCount++;
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        $this->stopAllCallCount++;
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
