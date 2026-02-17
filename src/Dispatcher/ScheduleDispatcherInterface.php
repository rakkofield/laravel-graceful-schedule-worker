<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

interface ScheduleDispatcherInterface
{
    /**
     * Dispatch a single event.
     *
     * @param ClockAwareEvent $event The schedule event to execute
     * @param DateTimeInterface $dueAt Scheduled due time
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
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
