<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;

class FakeDispatcher implements ScheduleDispatcherInterface
{
    /** @var DispatchResultInterface */
    private $resultToReturn;

    /** @var array<array{event: Event, container: Container}> */
    private $dispatched = [];

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
     * @return DispatchResultInterface
     */
    public function dispatchEvent(Event $event, Container $container): DispatchResultInterface
    {
        $this->dispatched[] = ['event' => $event, 'container' => $container];
        return $this->resultToReturn;
    }

    /**
     * Get dispatched events.
     *
     * @return array<array{event: Event, container: Container}>
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
    }
}
