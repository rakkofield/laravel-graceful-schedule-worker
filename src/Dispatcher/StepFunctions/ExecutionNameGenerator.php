<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Generates Step Functions Execution Names.
 *
 * Execution Name constraints:
 * - Maximum 80 characters
 * - Allowed characters: a-z, A-Z, 0-9, -, _
 */
class ExecutionNameGenerator implements ExecutionNameGeneratorInterface
{
    /**
     * {@inheritdoc}
     */
    public function generate(ClockAwareEvent $event, DateTimeInterface $dueAt): string
    {
        return MutexNameSanitizer::buildIdentifier($event->mutexName(), (string) $dueAt->getTimestamp());
    }
}
