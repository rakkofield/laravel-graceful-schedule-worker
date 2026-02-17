<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Exception when an execution with the same name already exists.
 *
 * Corresponds to the AWS Step Functions ExecutionAlreadyExists error.
 */
class ExecutionAlreadyExistsException extends StepFunctionsException
{
    /**
     * @param string $executionName Execution name to include in the error message
     * @param string $message Error message
     */
    public function __construct(string $executionName, string $message = '')
    {
        parent::__construct($message ?: "Execution '{$executionName}' already exists");
    }
}
