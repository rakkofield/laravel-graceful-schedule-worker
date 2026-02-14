<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * Failure result class for LocalDispatcher.
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
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $error,
        ?\Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->error = $error;
        $this->exception = $exception;
        $this->dispatchedAt = $dispatchedAt;
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
        return DispatcherType::LOCAL;
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
