<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

interface ScheduleDispatcherInterface
{
    /**
     * Dispatch a single event.
     *
     * `$dispatchedAt` is the dispatcher-agnostic representative time of the
     * dispatch action, sourced from the Orchestrator. For ordinary dispatches
     * it equals the just-frozen Schedule clock; for missed-event recovery the
     * Orchestrator passes a freshly-acquired wallclock value while `$dueAt`
     * remains the past scheduled instant. The value flows into the Result's
     * `getDispatchedAt()` and (for Step Functions) the payload's
     * `dispatchedAt` field unchanged.
     *
     * @param ClockAwareEvent $event The schedule event to execute
     * @param DateTimeInterface $dueAt Scheduled due time
     * @param DateTimeImmutable $dispatchedAt Representative dispatch instant
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt,
        DateTimeImmutable $dispatchedAt
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
