<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * Failure result class for StepFunctionsDispatcher.
 */
class FailedStepFunctionsDispatchResult extends AbstractDispatchResult implements FailedDispatchResultInterface
{
    /** @var string */
    private $executionName;

    /** @var string */
    private $error;

    /** @var \Throwable */
    private $exception;

    /**
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param \Throwable $exception
     * @param DateTimeImmutable $dispatchedAt
     * @param DateTimeImmutable $recordedAt
     */
    public function __construct(
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        \Throwable $exception,
        DateTimeImmutable $dispatchedAt,
        DateTimeImmutable $recordedAt
    ) {
        parent::__construct(
            $eventIdentifier,
            $eventCommand,
            DispatcherType::STEP_FUNCTIONS,
            $dispatchedAt,
            $recordedAt
        );
        $this->executionName = $executionName;
        $this->error = self::formatException($exception);
        $this->exception = $exception;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * {@inheritdoc}
     */
    public function getException(): ?\Throwable
    {
        return $this->exception;
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
