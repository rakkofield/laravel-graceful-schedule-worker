<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;

/**
 * StepFunctionsDispatcher で既に実行中の場合の結果クラス
 *
 * ExecutionAlreadyExists が発生した場合に使用します。
 */
class AlreadyRunningStepFunctionsDispatchResult implements AlreadyRunningDispatchResultInterface
{
    /** @var string */
    private $executionName;

    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /**
     * @param string $executionName
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt
    ) {
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
     * Execution Name を取得
     *
     * @return string
     */
    public function getExecutionName(): string
    {
        return $this->executionName;
    }
}
