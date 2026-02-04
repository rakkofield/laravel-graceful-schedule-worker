<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;

/**
 * LocalDispatcher の失敗結果クラス
 */
class FailedLocalDispatchResult implements FailedDispatchResultInterface
{
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
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $error
     * @param \Throwable|null $exception
     * @param DateTimeImmutable|null $dispatchedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $error,
        ?\Throwable $exception = null,
        ?DateTimeImmutable $dispatchedAt = null
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->error = $error;
        $this->exception = $exception;
        $this->dispatchedAt = $dispatchedAt ?? new DateTimeImmutable();
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted(): bool
    {
        return false;
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
        return 'local';
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
}
