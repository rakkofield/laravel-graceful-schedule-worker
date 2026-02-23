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
     * @return StartExecutionInput
     * @throws StepFunctionsException if payload encoding fails
     */
    public function create(ClockAwareEvent $event, DateTimeInterface $dueAt): StartExecutionInput;
}
