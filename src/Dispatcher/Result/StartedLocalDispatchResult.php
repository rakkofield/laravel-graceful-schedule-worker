<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use Symfony\Component\Process\Process;

/**
 * Success result class for LocalDispatcher.
 *
 * Holds the Process object and enables process state management.
 */
class StartedLocalDispatchResult extends AbstractDispatchResult implements StartedDispatchResultInterface
{
    /** @var Process */
    private $process;

    /** @var ClockAwareEvent|null */
    private $event;

    /**
     * @param Process $process
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     * @param ClockAwareEvent|null $event The event to run afterCallbacks on
     */
    public function __construct(
        Process $process,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt,
        ?ClockAwareEvent $event = null
    ) {
        parent::__construct($eventIdentifier, $eventCommand, DispatcherType::LOCAL, $dispatchedAt);
        $this->process = $process;
        $this->event = $event;
    }

    /**
     * Get the Process object.
     *
     * @return Process
     */
    public function getProcess(): Process
    {
        return $this->process;
    }

    /**
     * Check whether the process is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Get the process exit code.
     *
     * @return int|null
     */
    public function getExitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    /**
     * Get the event.
     *
     * @return ClockAwareEvent|null
     */
    public function getEvent(): ?ClockAwareEvent
    {
        return $this->event;
    }

    /**
     * Run afterCallbacks on the event with the process exit code.
     *
     * @param Container $container
     * @return void
     */
    public function runAfterCallbacks(Container $container): void
    {
        // $event is null for foreground results; afterCallbacks are already
        // called directly in dispatchEvent(), so this is a no-op.
        if ($this->event === null) {
            return;
        }

        $exitCode = $this->getExitCode() ?? LocalDispatcher::EXIT_CODE_SIGTERM;
        $this->event->callAfterCallbacksWithExitCode($container, $exitCode);
    }
}
