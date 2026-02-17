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
class StartedLocalDispatchResult implements StartedDispatchResultInterface
{
    /** @var Process */
    private $process;

    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

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
        $this->process = $process;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatchedAt = $dispatchedAt;
        $this->event = $event;
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
        return DispatcherType::LOCAL;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatchedAt(): DateTimeImmutable
    {
        return $this->dispatchedAt;
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
