<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Builds the payload for Step Functions StartExecution.
 *
 * Implement this interface to customize payload generation.
 * Bind your implementation to this interface in a ServiceProvider
 * to replace the default behavior.
 */
interface PayloadBuilderInterface
{
    /**
     * Build a Payload for the given event and due time.
     *
     * @param ClockAwareEvent $event
     * @param DateTimeInterface $dueAt
     * @param int $lockTtlSeconds
     * @return Payload
     */
    public function build(ClockAwareEvent $event, DateTimeInterface $dueAt, int $lockTtlSeconds): Payload;
}
