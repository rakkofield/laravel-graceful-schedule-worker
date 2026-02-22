<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * Skipped dispatch result class.
 *
 * Used when dispatch is skipped, such as lock acquisition failure.
 */
class SkippedDispatchResult extends AbstractDispatchResult implements SkippedDispatchResultInterface
{
    /** @var string */
    private $reason;

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
        parent::__construct($eventIdentifier, $eventCommand, $dispatcherType, $dispatchedAt);
        $this->reason = $reason;
    }

    /**
     * Create a result for withoutOverlapping skip (local dispatcher).
     *
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     * @return self
     */
    public static function forOverlapping(
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt
    ): self {
        return new self($eventIdentifier, $eventCommand, 'withoutOverlapping', $dispatchedAt, DispatcherType::LOCAL);
    }

    /**
     * Returns a callable factory for creating SkippedDispatchResult instances.
     *
     * This allows callers to create instances without a direct dependency on this concrete class.
     *
     * @return callable(string, string, string, DateTimeImmutable, string): self
     */
    public static function factory(): callable
    {
        return function (
            string $eventIdentifier,
            string $eventCommand,
            string $reason,
            DateTimeImmutable $dispatchedAt,
            string $dispatcherType
        ): self {
            return new self($eventIdentifier, $eventCommand, $reason, $dispatchedAt, $dispatcherType);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
