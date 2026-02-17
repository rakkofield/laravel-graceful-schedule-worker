<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Fixed Execution Name Generator for testing
 *
 * Always returns a specified fixed name.
 * Used in ExecutionAlreadyExists tests to send the same name but different input.
 */
class FixedExecutionNameGenerator implements ExecutionNameGeneratorInterface
{
    /** @var string */
    private $fixedName;

    /**
     * @param string $fixedName The fixed name to return
     */
    public function __construct(string $fixedName)
    {
        $this->fixedName = $fixedName;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(ClockAwareEvent $event, DateTimeInterface $dueAt): string
    {
        return $this->fixedName;
    }
}
