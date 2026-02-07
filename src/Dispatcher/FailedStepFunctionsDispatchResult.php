<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;

/**
 * StepFunctionsDispatcher の失敗結果クラス
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
    private function __construct(
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
     * 失敗した場合の結果を作成
     *
     * @param string $executionName
     * @param string $identifier
     * @param string|null $command
     * @param string $error
     * @param \Throwable|null $exception
     * @return self
     */
    public static function failed(
        string $executionName,
        string $identifier,
        ?string $command,
        string $error,
        ?\Throwable $exception = null
    ): self {
        return new self(
            $executionName,
            $identifier,
            $command ?? '',
            $error,
            $exception,
            new DateTimeImmutable()
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
        return 'stepfunctions';
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
     * Execution Name を取得
     *
     * @return string
     */
    public function getExecutionName(): string
    {
        return $this->executionName;
    }
}
