<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Interface for the Step Functions client.
 *
 * Defines only the required methods to avoid direct dependency on the AWS SDK.
 */
interface StepFunctionsClientInterface
{
    /**
     * Start a State Machine execution.
     *
     * @param array{stateMachineArn: string, name?: string, input?: string} $args
     * @return StartExecutionResult
     * @throws ExecutionAlreadyExistsException If an execution with the same name already exists
     * @throws StepFunctionsException For other Step Functions errors
     */
    public function startExecution(array $args): StartExecutionResult;
}
