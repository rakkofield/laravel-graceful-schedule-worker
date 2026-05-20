<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

class FakeSkippedDispatchResult implements SkippedDispatchResultInterface
{
    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var string */
    private $reason;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var DateTimeImmutable */
    private $recordedAt;

    /** @var string */
    private $dispatcherType;

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $reason
     * @param string $dispatcherType
     * @param DateTimeImmutable|null $dispatchedAt
     * @param DateTimeImmutable|null $recordedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $reason,
        string $dispatcherType = 'fake',
        ?DateTimeImmutable $dispatchedAt = null,
        ?DateTimeImmutable $recordedAt = null
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->reason = $reason;
        $this->dispatcherType = $dispatcherType;
        $this->dispatchedAt = $dispatchedAt ?? new DateTimeImmutable();
        $this->recordedAt = $recordedAt ?? new DateTimeImmutable();
    }

    /**
     * Create a skipped result.
     *
     * @param string $identifier
     * @param string $command
     * @param string $reason
     * @param string $dispatcherType
     * @return self
     */
    public static function create(
        string $identifier,
        string $command,
        string $reason = 'lock_not_acquired',
        string $dispatcherType = 'fake'
    ): self {
        return new self($identifier, $command, $reason, $dispatcherType);
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

    /**
     * {@inheritdoc}
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
