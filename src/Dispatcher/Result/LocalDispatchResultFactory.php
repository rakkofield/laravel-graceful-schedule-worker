<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use Symfony\Component\Process\Process;

/**
 * Factory for LocalDispatcher's result objects (started / failed / skipped).
 *
 * Centralises Result construction so LocalDispatcher does not need a Clock
 * dependency or knowledge of `DispatcherType::LOCAL`. `started()` and
 * `failed()` build Local-specific result classes; `skipped()` delegates to the
 * shared {@see SkippedDispatchResultFactory} with `DispatcherType::LOCAL`
 * baked in so callers cannot accidentally mis-tag a Local skip.
 */
class LocalDispatchResultFactory
{
    /** @var ClockInterface */
    private $clock;

    /** @var SkippedDispatchResultFactory */
    private $skippedResultFactory;

    public function __construct(ClockInterface $clock, SkippedDispatchResultFactory $skippedResultFactory)
    {
        $this->clock = $clock;
        $this->skippedResultFactory = $skippedResultFactory;
    }

    public function started(
        Process $process,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt,
        ?ClockAwareEvent $event = null
    ): StartedLocalDispatchResult {
        return new StartedLocalDispatchResult(
            $process,
            $eventIdentifier,
            $eventCommand,
            $dispatchedAt,
            $this->clock->now(),
            $event
        );
    }

    public function failed(
        string $eventIdentifier,
        string $eventCommand,
        \Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ): FailedDispatchResultInterface {
        return new FailedLocalDispatchResult(
            $eventIdentifier,
            $eventCommand,
            $exception,
            $dispatchedAt,
            $this->clock->now()
        );
    }

    public function skipped(
        string $eventIdentifier,
        string $eventCommand,
        string $reason,
        DateTimeImmutable $dispatchedAt
    ): SkippedDispatchResultInterface {
        return $this->skippedResultFactory->create(
            $eventIdentifier,
            $eventCommand,
            $reason,
            $dispatchedAt,
            DispatcherType::LOCAL
        );
    }
}
