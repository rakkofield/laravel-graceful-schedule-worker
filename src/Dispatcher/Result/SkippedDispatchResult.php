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

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $reason Skip reason
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $reason,
        DateTimeImmutable $dispatchedAt
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->reason = $reason;
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
     * Get the dispatcher type.
     *
     * SkippedDispatchResult is exclusive to TrackingDispatcher,
     * so it always returns 'tracking'.
     *
     * @return string
     */
    public function getDispatcherType(): string
    {
        return 'tracking';
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
