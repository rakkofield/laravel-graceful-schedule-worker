<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;

/**
 * StepFunctionsDispatcher の成功結果クラス
 *
 * Execution 情報を保持し、Step Functions の実行状態を管理します。
 */
class StartedStepFunctionsDispatchResult implements StartedDispatchResultInterface
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

    /** @var bool */
    private $wasAlreadyRunning;

    /**
     * @param string|null $executionArn
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param bool $wasAlreadyRunning
     * @param DateTimeImmutable|null $dispatchedAt
     */
    private function __construct(
        ?string $executionArn,
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        bool $wasAlreadyRunning,
        ?DateTimeImmutable $dispatchedAt = null
    ) {
        $this->executionArn = $executionArn;
        $this->executionName = $executionName;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->wasAlreadyRunning = $wasAlreadyRunning;
        $this->dispatchedAt = $dispatchedAt ?? new DateTimeImmutable();
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
            false
        );
    }

    /**
     * 既に実行中の場合の結果を作成
     *
     * ExecutionAlreadyExists は正常系として扱い、StartedDispatchResultInterface を実装します。
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
            true
        );
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
     * @return string|null 成功時は ARN、既存実行時は null
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
}
