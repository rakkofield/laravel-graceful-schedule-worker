<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;

/**
 * StepFunctionsDispatcher 用の結果クラス
 *
 * Execution 情報を保持し、Step Functions の実行状態を管理します。
 */
class StepFunctionsDispatchResult implements DispatchResultInterface
{
    /** @var string|null */
    private $executionArn;

    /** @var string */
    private $executionName;

    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var string|null */
    private $error;

    /** @var bool */
    private $wasAlreadyRunning;

    /** @var \Throwable|null */
    private $exception;

    /**
     * @param string|null $executionArn
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     * @param string|null $error
     * @param bool $wasAlreadyRunning
     * @param \Throwable|null $exception
     */
    private function __construct(
        $executionArn,
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt,
        $error,
        bool $wasAlreadyRunning,
        \Throwable $exception = null
    ) {
        $this->executionArn = $executionArn;
        $this->executionName = $executionName;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatchedAt = $dispatchedAt;
        $this->error = $error;
        $this->wasAlreadyRunning = $wasAlreadyRunning;
        $this->exception = $exception;
    }

    /**
     * 成功した場合の結果を作成
     *
     * @param string $executionArn
     * @param string $executionName
     * @param string $identifier
     * @param string $command
     * @return self
     */
    public static function success(
        string $executionArn,
        string $executionName,
        string $identifier,
        string $command
    ): self {
        return new self(
            $executionArn,
            $executionName,
            $identifier,
            $command,
            new DateTimeImmutable(),
            null,
            false
        );
    }

    /**
     * 既に実行中の場合の結果を作成
     *
     * ExecutionAlreadyExists は正常系として扱い、isStarted は true を返します。
     *
     * @param string $executionName
     * @param string $identifier
     * @param string $command
     * @return self
     */
    public static function alreadyRunning(
        string $executionName,
        string $identifier,
        string $command
    ): self {
        return new self(
            null,
            $executionName,
            $identifier,
            $command,
            new DateTimeImmutable(),
            null,
            true
        );
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
        \Throwable $exception = null
    ): self {
        return new self(
            null,
            $executionName,
            $identifier,
            $command ?? '',
            new DateTimeImmutable(),
            $error,
            false,
            $exception
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted(): bool
    {
        return $this->error === null;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string
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
     * Execution ARN を取得
     *
     * @return string|null 成功時は ARN、失敗または既存実行時は null
     */
    public function getExecutionArn(): ?string
    {
        return $this->executionArn;
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

    /**
     * 既に実行中だったかどうか
     *
     * ExecutionAlreadyExists で成功として扱われた場合に true を返します。
     *
     * @return bool
     */
    public function wasAlreadyRunning(): bool
    {
        return $this->wasAlreadyRunning;
    }

    /**
     * {@inheritdoc}
     */
    public function getException(): ?\Throwable
    {
        return $this->exception;
    }
}
