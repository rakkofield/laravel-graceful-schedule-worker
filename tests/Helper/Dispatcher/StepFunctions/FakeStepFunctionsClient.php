<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;

/**
 * Fake Step Functions client for testing
 */
class FakeStepFunctionsClient implements StepFunctionsClientInterface
{
    /** @var string */
    private $stateMachineArn;

    /** @var array<int, array{name: string, input: string, executionArn: string, startDate: DateTimeImmutable}> */
    private $executions = [];

    /** @var array<string, true> */
    private $existingExecutions = [];

    /** @var \Exception|null */
    private $nextError = null;

    /**
     * @param string $stateMachineArn
     */
    public function __construct(string $stateMachineArn = '')
    {
        $this->stateMachineArn = $stateMachineArn;
    }

    /**
     * {@inheritdoc}
     */
    public function startExecution(StartExecutionInput $input): StartExecutionResult
    {
        if ($this->nextError !== null) {
            $error = $this->nextError;
            $this->nextError = null;
            throw $error;
        }

        $name = $input->getName();

        if (isset($this->existingExecutions[$name])) {
            throw new ExecutionAlreadyExistsException($name);
        }

        $executionArn = sprintf(
            'arn:aws:states:ap-northeast-1:000000000000:execution:test-state-machine:%s',
            $name
        );
        $startDate = new DateTimeImmutable();

        $this->executions[] = [
            'name' => $name,
            'input' => $input->getInput(),
            'executionArn' => $executionArn,
            'startDate' => $startDate,
        ];

        // Prevent re-execution with the same name
        $this->existingExecutions[$name] = true;

        return new StartExecutionResult($executionArn, $startDate);
    }

    /**
     * @return string
     */
    public function getStateMachineArn(): string
    {
        return $this->stateMachineArn;
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
     * Configure the next startExecution to throw a custom exception
     *
     * @param \Exception $exception
     * @return void
     */
    public function willThrowCustomException(\Exception $exception): void
    {
        $this->nextError = $exception;
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
