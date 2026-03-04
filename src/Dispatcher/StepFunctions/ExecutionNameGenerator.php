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
    /** @var MutexNameSanitizer */
    private $sanitizer;

    /**
     * @param MutexNameSanitizer $sanitizer
     */
    public function __construct(MutexNameSanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(ClockAwareEvent $event, DateTimeInterface $dueAt): string
    {
        return $this->sanitizer->buildIdentifier($event->mutexName(), (string) $dueAt->getTimestamp());
    }
}
