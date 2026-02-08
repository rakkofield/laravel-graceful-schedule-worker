<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;

interface ScheduleDispatcherInterface
{
    /**
     * Dispatch a single event.
     *
     * @param Event $event The schedule event to execute
     * @param Container $container Laravel container instance
     * @param DateTimeInterface $dueAt Scheduled due time
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(
        Event $event,
        Container $container,
        DateTimeInterface $dueAt
    ): DispatchResultInterface;

    /**
     * Clean up completed processes.
     *
     * @return void
     */
    public function cleanup(): void;

    /**
     * Stop all processes.
     *
     * @return void
     */
    public function stopAll(): void;
}
