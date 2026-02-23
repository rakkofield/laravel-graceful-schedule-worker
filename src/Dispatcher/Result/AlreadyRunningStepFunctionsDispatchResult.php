<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * Result class for when execution is already running in StepFunctionsDispatcher.
 *
 * Used when ExecutionAlreadyExists occurs.
 */
class AlreadyRunningStepFunctionsDispatchResult extends AbstractDispatchResult implements AlreadyRunningDispatchResultInterface
{
    /** @var string */
    private $executionName;

    /**
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt
    ) {
        parent::__construct($eventIdentifier, $eventCommand, DispatcherType::STEP_FUNCTIONS, $dispatchedAt);
        $this->executionName = $executionName;
    }

    /**
     * Get the Execution Name.
     *
     * @return string
     */
    public function getExecutionName(): string
    {
        return $this->executionName;
    }
}
