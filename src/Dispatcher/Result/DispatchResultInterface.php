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
     * @return \DateTimeImmutable Dispatch timestamp
     */
    public function getDispatchedAt(): \DateTimeImmutable;
}
