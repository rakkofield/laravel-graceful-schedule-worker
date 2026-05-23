<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

class FakeAlreadyRunningDispatchResult implements AlreadyRunningDispatchResultInterface
{
    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var string */
    private $dispatcherType;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var DateTimeImmutable */
    private $recordedAt;

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $dispatcherType
     * @param DateTimeImmutable|null $dispatchedAt
     * @param DateTimeImmutable|null $recordedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $dispatcherType,
        ?DateTimeImmutable $dispatchedAt = null,
        ?DateTimeImmutable $recordedAt = null
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatcherType = $dispatcherType;
        $this->dispatchedAt = $dispatchedAt ?? new DateTimeImmutable();
        $this->recordedAt = $recordedAt ?? new DateTimeImmutable();
    }

    /**
     * Create an already running result.
     *
     * @param string $identifier
     * @param string $command
     * @param string $type
     * @return self
     */
    public static function create(string $identifier, string $command, string $type = 'fake'): self
    {
        return new self($identifier, $command, $type);
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
    public function getRecordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
