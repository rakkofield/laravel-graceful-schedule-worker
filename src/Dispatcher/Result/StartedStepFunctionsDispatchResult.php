<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * Success result class for StepFunctionsDispatcher.
 *
 * Holds execution information and manages Step Functions execution state.
 */
class StartedStepFunctionsDispatchResult extends AbstractDispatchResult implements StartedDispatchResultInterface
{
    /** @var string */
    private $executionArn;

    /** @var string */
    private $executionName;

    /**
     * @param string $executionArn
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $executionArn,
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt
    ) {
        parent::__construct($eventIdentifier, $eventCommand, DispatcherType::STEP_FUNCTIONS, $dispatchedAt);
        $this->executionArn = $executionArn;
        $this->executionName = $executionName;
    }

    /**
     * Get the Execution ARN.
     *
     * @return string
     */
    public function getExecutionArn(): string
    {
        return $this->executionArn;
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
