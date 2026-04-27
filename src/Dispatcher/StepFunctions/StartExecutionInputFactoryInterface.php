<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

interface StartExecutionInputFactoryInterface
{
    /**
     * Build a StartExecutionInput from an event and its scheduled due time.
     *
     * @param ClockAwareEvent $event
     * @param DateTimeInterface $dueAt
     * @param DateTimeInterface $dispatchedAt Representative dispatch instant supplied by the Orchestrator
     *        (used by AcquireLock as `:now`).
     * @return StartExecutionInput
     */
    public function create(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt,
        DateTimeInterface $dispatchedAt
    ): StartExecutionInput;
}
