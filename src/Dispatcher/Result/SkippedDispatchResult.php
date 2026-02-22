<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;

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
     * {@inheritdoc}
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
