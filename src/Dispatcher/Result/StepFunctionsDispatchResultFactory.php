<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

/**
 * Factory for StepFunctionsDispatcher's result objects.
 *
 * Centralises Result construction so StepFunctionsDispatcher does not need a
 * Clock dependency. Each factory method stamps the Result's `recordedAt` with
 * the factory's own clock; the caller supplies `dispatchedAt` (the Orchestrator's
 * representative dispatch instant).
 */
class StepFunctionsDispatchResultFactory
{
    /** @var ClockInterface */
    private $clock;

    public function __construct(ClockInterface $clock)
    {
        $this->clock = $clock;
    }

    public function started(
        string $executionArn,
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt
    ): StartedDispatchResultInterface {
        return new StartedStepFunctionsDispatchResult(
            $executionArn,
            $executionName,
            $eventIdentifier,
            $eventCommand,
            $dispatchedAt,
            $this->clock->now()
        );
    }

    public function alreadyRunning(
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt
    ): AlreadyRunningDispatchResultInterface {
        return new AlreadyRunningStepFunctionsDispatchResult(
            $executionName,
            $eventIdentifier,
            $eventCommand,
            $dispatchedAt,
            $this->clock->now()
        );
    }

    public function failed(
        string $executionName,
        string $eventIdentifier,
        string $eventCommand,
        \Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ): FailedDispatchResultInterface {
        return new FailedStepFunctionsDispatchResult(
            $executionName,
            $eventIdentifier,
            $eventCommand,
            $exception,
            $dispatchedAt,
            $this->clock->now()
        );
    }
}
