<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

/**
 * Interface for type-safe handling of dispatcher results.
 *
 * Defines common metadata; dispatcher-specific information is held by concrete classes.
 *
 * Use StartedDispatchResultInterface for success and FailedDispatchResultInterface
 * for failure to handle results in a type-safe manner.
 */
interface DispatchResultInterface
{
    /**
     * Get the event identifier (mutex name).
     *
     * @return string Event identifier
     */
    public function getEventIdentifier(): string;

    /**
     * Get the executed command.
     *
     * LocalDispatcher: shell command (including output redirection)
     * StepFunctionsDispatcher: value of Event::command
     *
     * @return string Executed command
     */
    public function getEventCommand(): string;

    /**
     * Get the dispatcher type.
     *
     * @return string Dispatcher type ('local' | 'stepfunctions')
     */
    public function getDispatcherType(): string;

    /**
     * Get the dispatch timestamp.
     *
     * Single representative time of the dispatch event, sourced from the
     * Orchestrator. Shared with the Step Functions Payload's `dispatchedAt`
     * so the wire-level value and the Result-level value agree.
     *
     * @return \DateTimeImmutable Dispatch timestamp
     */
    public function getDispatchedAt(): \DateTimeImmutable;

    /**
     * Get the moment this Result object was constructed.
     *
     * Captured by the Result factory's clock at construction time, so it
     * differs from `getDispatchedAt()` by the latency of the dispatch
     * action (process start, StartExecution call, etc.). Use this when
     * you need the physical "result confirmed" timestamp rather than the
     * dispatch event's representative timestamp.
     *
     * @return \DateTimeImmutable Result-construction timestamp
     */
    public function getRecordedAt(): \DateTimeImmutable;
}
