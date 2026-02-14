<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * Failure result class for StepFunctionsDispatcher.
 */
class FailedStepFunctionsDispatchResult implements FailedDispatchResultInterface
{
    /** @var string */
    private $executionName;

    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var string */
    private $error;

    /** @var \Throwable|null */
    private $exception;

    /**
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $error
     * @param \Throwable|null $exception
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        string $error,
        ?\Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ) {
        $this->executionName = $executionName;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->error = $error;
        $this->exception = $exception;
        $this->dispatchedAt = $dispatchedAt;
    }

    /**
     * Create a result for a failed dispatch.
     *
     * @param string $executionName
     * @param string $identifier
     * @param string|null $command
     * @param string $error
     * @param \Throwable|null $exception
     * @param DateTimeImmutable $dispatchedAt
     * @return self
     */
    public static function failed(
        string $executionName,
        string $identifier,
        ?string $command,
        string $error,
        ?\Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ): self {
        return new self(
            $executionName,
            $identifier,
            $command ?? '',
            $error,
            $exception,
            $dispatchedAt
        );
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
    public function getEventIdentifier(): string
    {
        return $this->eventIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventCommand(): string
    {
        return $this->eventCommand;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatcherType(): string
    {
        return DispatcherType::STEP_FUNCTIONS;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatchedAt(): DateTimeImmutable
    {
        return $this->dispatchedAt;
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
