<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use Symfony\Component\Process\Process;

class LocalDispatcher implements ScheduleDispatcherInterface
{
    /**
     * @var string|null
     */
    private $basePath;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array<StartedLocalDispatchResult>
     */
    protected $runningProcesses = [];

    /**
     * @var SleeperInterface
     */
    private $sleeper;

    /**
     * @var float
     */
    private $stopTimeout;

    /**
     * @param string|null $basePath Working directory for processes (null uses the current directory)
     * @param LoggerInterface $logger Logger
     * @param SleeperInterface $sleeper Sleeper (for polling in stopAll)
     * @param float $stopTimeout Timeout in seconds for SIGTERM->SIGKILL wait in stopAll()
     */
    public function __construct(
        ?string $basePath,
        LoggerInterface $logger,
        SleeperInterface $sleeper,
        float $stopTimeout = 10.0
    ) {
        $this->basePath = $basePath;
        $this->logger = $logger;
        $this->sleeper = $sleeper;
        $this->stopTimeout = $stopTimeout;
    }

    /**
     * Dispatch a single event.
     *
     * Respects the Event's runInBackground setting and selects the appropriate execution path.
     * - beforeCallbacks are executed synchronously in the parent process
     * - runInBackground = true: strips & from buildCommand() and runs async via Process::start()
     *   (includes schedule:finish, afterCallbacks are executed by the child process)
     *   Uses buildProcessCommand() for ClockAwareEvent
     * - runInBackground = false: runs synchronously via buildCommand() and calls afterCallbacks directly
     *
     * @param Event $event The schedule event to execute
     * @param Container $container Laravel container instance
     * @param DateTimeInterface $dueAt Scheduled due time (unused in LocalDispatcher)
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        $identifier = $event->mutexName();

        try {
            $event->callBeforeCallbacks($container);

            if ($event->runInBackground) {
                // Background: generate command including schedule:finish, strip &, and run async
                if ($event instanceof ClockAwareEvent) {
                    $fullCommand = $event->buildProcessCommand();
                } else {
                    $fullCommand = $event->buildCommand();
                    // Strip trailing & from the built command (buildCommand for non-ClockAwareEvent includes &)
                    $fullCommand = preg_replace('/\s+&\s*$/', '', $fullCommand) ?? $fullCommand;
                }
                $process = Process::fromShellCommandline($fullCommand, $this->basePath);
                $process->setTimeout(null);
                $process->start();

                $result = new StartedLocalDispatchResult($process, $identifier, $fullCommand, new DateTimeImmutable());
                $this->runningProcesses[] = $result;
                return $result;
            }

            // Foreground: run command synchronously without schedule:finish and call afterCallbacks directly
            $fullCommand = $event->buildCommand();
            $process = Process::fromShellCommandline($fullCommand, $this->basePath);
            $process->setTimeout(null);
            $process->run();

            try {
                $event->callAfterCallbacksWithExitCode($container, (int) $process->getExitCode());
            } catch (\Exception $e) {
                // afterCallback failures don't affect the dispatch result.
                // \Error is not caught here; it propagates to the command-level handler.
                $this->logger->warning('[GracefulScheduleWorker] afterCallback failed', [
                    'event' => $identifier,
                    'exitCode' => $process->getExitCode(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }

            return new StartedLocalDispatchResult($process, $identifier, $fullCommand, new DateTimeImmutable());
        } catch (\Exception $e) {
            // Note: \Error is not caught (fatal errors propagate to the caller)
            $error = get_class($e) . ': ' . $e->getMessage();

            return new FailedLocalDispatchResult($identifier, $event->command, $error, $e, new DateTimeImmutable());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        $this->runningProcesses = array_values(
            array_filter(
                $this->runningProcesses,
                function (StartedLocalDispatchResult $result) {
                    return $result->isRunning();
                }
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        // Phase 1: Send SIGTERM to all running processes simultaneously
        foreach ($this->runningProcesses as $result) {
            try {
                $process = $result->getProcess();
                if ($process->isRunning()) {
                    $process->signal(SIGTERM);
                }
            } catch (\Exception $e) {
                $this->logger->warning('[GracefulScheduleWorker] Failed to send SIGTERM', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        // Phase 2: Poll and wait for all processes to terminate until timeout
        $deadline = microtime(true) + $this->stopTimeout;
        while (microtime(true) < $deadline) {
            $allStopped = true;
            foreach ($this->runningProcesses as $result) {
                if ($result->isRunning()) {
                    $allStopped = false;
                    break;
                }
            }
            if ($allStopped) {
                break;
            }
            $this->sleeper->sleep();
        }

        // Phase 3: Send SIGKILL to processes still running
        foreach ($this->runningProcesses as $result) {
            try {
                $process = $result->getProcess();
                if ($process->isRunning()) {
                    $process->signal(SIGKILL);
                }
            } catch (\Exception $e) {
                $this->logger->warning('[GracefulScheduleWorker] Failed to send SIGKILL', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $this->runningProcesses = [];
    }
}
