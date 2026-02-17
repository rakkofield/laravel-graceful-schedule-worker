<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

/**
 * Skipped dispatch result class.
 *
 * Used when dispatch is skipped, such as lock acquisition failure.
 */
class SkippedDispatchResult implements SkippedDispatchResultInterface
{
    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var string */
    private $reason;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var string */
    private $dispatcherType;

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $reason Skip reason
     * @param DateTimeImmutable $dispatchedAt
     * @param string $dispatcherType
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $reason,
        DateTimeImmutable $dispatchedAt,
        string $dispatcherType
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->reason = $reason;
        $this->dispatchedAt = $dispatchedAt;
        $this->dispatcherType = $dispatcherType;
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
    public function getReason(): string
    {
        return $this->reason;
    }
}
