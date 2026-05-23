<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

/**
 * Base class providing common dispatch result metadata.
 *
 * Concrete classes extend this to avoid repeating the 5 shared fields
 * (eventIdentifier, eventCommand, dispatcherType, dispatchedAt, recordedAt)
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

    /** @var DateTimeImmutable */
    private $recordedAt;

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $dispatcherType
     * @param DateTimeImmutable $dispatchedAt Representative time of the dispatch event (sourced from the Orchestrator).
     * @param DateTimeImmutable $recordedAt Moment this Result was constructed (set by the Result factory).
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $dispatcherType,
        DateTimeImmutable $dispatchedAt,
        DateTimeImmutable $recordedAt
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatcherType = $dispatcherType;
        $this->dispatchedAt = $dispatchedAt;
        $this->recordedAt = $recordedAt;
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
     * Format an exception into a human-readable error string.
     *
     * @param \Throwable $e
     * @return string
     */
    protected static function formatException(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage();
    }
}
