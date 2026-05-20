<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

/**
 * Factory for SkippedDispatchResult, shared across dispatchers.
 *
 * The dispatcher type is supplied by the caller because this factory is used
 * from both LocalDispatcher (mutex skip → DispatcherType::LOCAL) and
 * TrackingDispatcher (tracker lock skip → derived from the inner event), so
 * the dispatcher-specific Result factories cannot bake the value in.
 */
final class SkippedDispatchResultFactory
{
    /** @var ClockInterface */
    private $clock;

    public function __construct(ClockInterface $clock)
    {
        $this->clock = $clock;
    }

    public function create(
        string $eventIdentifier,
        string $eventCommand,
        string $reason,
        DateTimeImmutable $dispatchedAt,
        string $dispatcherType
    ): SkippedDispatchResultInterface {
        return new SkippedDispatchResult(
            $eventIdentifier,
            $eventCommand,
            $reason,
            $dispatchedAt,
            $dispatcherType,
            $this->clock->now()
        );
    }
}
