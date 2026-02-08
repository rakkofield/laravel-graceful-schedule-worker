<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;

/**
 * Fake Step Functions client for testing
 */
class FakeStepFunctionsClient implements StepFunctionsClientInterface
{
    /** @var array<int, array{stateMachineArn: string, name: string, input: string, executionArn: string, startDate: DateTimeImmutable}> */
    private $executions = [];

    /** @var array<string, true> */
    private $existingExecutions = [];

    /** @var StepFunctionsException|null */
    private $nextError = null;

    /**
     * {@inheritdoc}
     */
    public function startExecution(array $args): StartExecutionResult
    {
        if ($this->nextError !== null) {
            $error = $this->nextError;
            $this->nextError = null;
            throw $error;
        }

        $name = $args['name'] ?? 'unnamed-' . count($this->executions);

        if (isset($this->existingExecutions[$name])) {
            throw new ExecutionAlreadyExistsException($name);
        }

        $executionArn = sprintf(
            'arn:aws:states:ap-northeast-1:000000000000:execution:test-state-machine:%s',
            $name
        );
        $startDate = new DateTimeImmutable();

        $this->executions[] = [
            'stateMachineArn' => $args['stateMachineArn'],
            'name' => $name,
            'input' => $args['input'] ?? '{}',
            'executionArn' => $executionArn,
            'startDate' => $startDate,
        ];

        // Prevent re-execution with the same name
        $this->existingExecutions[$name] = true;

        return new StartExecutionResult($executionArn, $startDate);
    }

    /**
     * Configure the next startExecution to throw ExecutionAlreadyExists
     *
     * @param string $executionName
     * @return void
     */
    public function willThrowExecutionAlreadyExists(string $executionName): void
    {
        $this->nextError = new ExecutionAlreadyExistsException($executionName);
    }

    /**
     * Configure the next startExecution to throw a general error
     *
     * @param string $message
     * @return void
     */
    public function willThrowError(string $message): void
    {
        $this->nextError = new StepFunctionsException($message);
    }

    /**
     * Add an existing Execution (startExecution with the same name will fail)
     *
     * @param string $name
     * @return void
     */
    public function addExistingExecution(string $name): void
    {
        $this->existingExecutions[$name] = true;
    }

    /**
     * Get the list of executed Executions
     *
     * @return array<int, array{
     *     stateMachineArn: string,
     *     name: string,
     *     input: string,
     *     executionArn: string,
     *     startDate: DateTimeImmutable
     * }>
     */
    public function getExecutions(): array
    {
        return $this->executions;
    }

    /**
     * Get the last executed Execution
     *
     * @return array{
     *     stateMachineArn: string,
     *     name: string,
     *     input: string,
     *     executionArn: string,
     *     startDate: DateTimeImmutable
     * }|null
     */
    public function getLastExecution(): ?array
    {
        if (empty($this->executions)) {
            return null;
        }
        return $this->executions[count($this->executions) - 1];
    }

    /**
     * Reset state
     *
     * @return void
     */
    public function reset(): void
    {
        $this->executions = [];
        $this->existingExecutions = [];
        $this->nextError = null;
    }
}
