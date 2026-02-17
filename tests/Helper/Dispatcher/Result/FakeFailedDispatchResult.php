<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

class FakeFailedDispatchResult implements FailedDispatchResultInterface
{
    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var string */
    private $dispatcherType;

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
     * @param string $dispatcherType
     * @param \Throwable|null $exception
     * @param DateTimeImmutable|null $dispatchedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $error,
        string $dispatcherType,
        ?\Throwable $exception = null,
        ?DateTimeImmutable $dispatchedAt = null
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->error = $error;
        $this->dispatcherType = $dispatcherType;
        $this->exception = $exception;
        $this->dispatchedAt = $dispatchedAt ?? new DateTimeImmutable();
    }

    /**
     * Create a failed result.
     *
     * @param string $identifier
     * @param string $command
     * @param string $error
     * @param string $type
     * @param \Throwable|null $exception
     * @return self
     */
    public static function create(
        string $identifier,
        string $command,
        string $error,
        string $type = 'fake',
        ?\Throwable $exception = null
    ): self {
        return new self($identifier, $command, $error, $type, $exception);
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
        return $this->dispatcherType;
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
}
