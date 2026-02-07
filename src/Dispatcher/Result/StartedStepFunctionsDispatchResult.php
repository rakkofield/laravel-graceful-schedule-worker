<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

/**
 * StepFunctionsDispatcher の成功結果クラス
 *
 * Execution 情報を保持し、Step Functions の実行状態を管理します。
 */
class StartedStepFunctionsDispatchResult implements StartedDispatchResultInterface
{
    /** @var string */
    private $executionArn;

    /** @var string */
    private $executionName;

    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /**
     * @param string $executionArn
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $executionArn,
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt
    ) {
        $this->executionArn = $executionArn;
        $this->executionName = $executionName;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatchedAt = $dispatchedAt;
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
     * @return string
     */
    public function getExecutionArn(): string
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
}
