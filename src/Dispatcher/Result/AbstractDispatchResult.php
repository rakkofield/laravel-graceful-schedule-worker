<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

/**
 * Base class providing common dispatch result metadata.
 *
 * Concrete classes extend this to avoid repeating the 4 shared fields
 * (eventIdentifier, eventCommand, dispatcherType, dispatchedAt)
 * and their getter implementations.
 */
abstract class AbstractDispatchResult implements DispatchResultInterface
{
    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var string */
    private $dispatcherType;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $dispatcherType
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $dispatcherType,
        DateTimeImmutable $dispatchedAt
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatcherType = $dispatcherType;
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
        return $this->dispatcherType;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatchedAt(): DateTimeImmutable
    {
        return $this->dispatchedAt;
    }
}
