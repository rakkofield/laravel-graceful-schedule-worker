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
     */
    private function __construct(
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        \Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ) {
        parent::__construct($eventIdentifier, $eventCommand, DispatcherType::STEP_FUNCTIONS, $dispatchedAt);
        $this->executionName = $executionName;
        $this->error = self::formatException($exception);
        $this->exception = $exception;
    }

    /**
     * Create a result for a failed dispatch.
     *
     * @param string $executionName
     * @param string $identifier
     * @param string|null $command
     * @param \Throwable $exception
     * @param DateTimeImmutable $dispatchedAt
     * @return self
     */
    public static function failed(
        string $executionName,
        string $identifier,
        ?string $command,
        \Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ): self {
        return new self(
            $executionName,
            $identifier,
            $command ?? '',
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
