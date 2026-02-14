<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FinishCommandTemplate;
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

    /** @var FinishCommandTemplate|null */
    private $finishCommandTemplate;

    /**
     * @param Process $process
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     * @param FinishCommandTemplate|null $finishCommandTemplate Template for schedule:finish
     */
    public function __construct(
        Process $process,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt,
        ?FinishCommandTemplate $finishCommandTemplate = null
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
     * Get the finish command template.
     *
     * @return FinishCommandTemplate|null Template, or null if not set
     */
    public function getFinishCommandTemplate(): ?FinishCommandTemplate
    {
        return $this->finishCommandTemplate;
    }
}
