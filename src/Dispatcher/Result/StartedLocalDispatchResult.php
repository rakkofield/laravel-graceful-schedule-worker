<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
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

    /** @var string|null */
    private $finishCommandTemplate;

    /**
     * @param Process $process
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     * @param string|null $finishCommandTemplate sprintf-compatible template for schedule:finish
     */
    public function __construct(
        Process $process,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt,
        ?string $finishCommandTemplate = null
    ) {
        $this->process = $process;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatchedAt = $dispatchedAt;
        $this->finishCommandTemplate = $finishCommandTemplate;
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
        return 'local';
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
     * Get the finish command template.
     *
     * @return string|null sprintf-compatible template, or null if not set
     */
    public function getFinishCommandTemplate(): ?string
    {
        return $this->finishCommandTemplate;
    }
}
