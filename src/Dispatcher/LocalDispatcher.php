<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use Symfony\Component\Process\Process;

class LocalDispatcher implements ScheduleDispatcherInterface
{
    /** @var int */
    public const EXIT_CODE_SIGTERM = 143;

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
     * - runInBackground = true: uses buildProcessCommand() with exec, runs async via Process::start()
     *   (schedule:finish is handled by PHP-side cleanup/stopAll)
     * - runInBackground = false: runs synchronously via buildCommand() and calls afterCallbacks directly
     *
     * @param ClockAwareEvent $event The schedule event to execute
     * @param Container $container Laravel container instance
     * @param DateTimeInterface $dueAt Scheduled due time (unused in LocalDispatcher)
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        Container $container,
        DateTimeInterface $dueAt
    ): DispatchResultInterface {
        $identifier = $event->mutexName();

        try {
            // Handle withoutOverlapping: atomically try to claim the mutex
            if ($event->withoutOverlapping && !$event->mutex->create($event)) {
                return new SkippedDispatchResult(
                    $identifier,
                    (string) $event->command,
                    'withoutOverlapping',
                    new DateTimeImmutable(),
                    'local'
                );
            }

            $event->callBeforeCallbacks($container);

            if ($event->runInBackground) {
                // Background: generate exec command (no schedule:finish), run async
                $fullCommand = $event->buildProcessCommand();
                $finishCommandTemplate = $event->buildFinishCommandTemplate();
                $process = Process::fromShellCommandline($fullCommand, $this->basePath);
                $process->setTimeout(null);
                $process->start();

                $result = new StartedLocalDispatchResult(
                    $process,
                    $identifier,
                    $fullCommand,
                    new DateTimeImmutable(),
                    $finishCommandTemplate
                );
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
        $stillRunning = [];
        foreach ($this->runningProcesses as $result) {
            if ($result->isRunning()) {
                $stillRunning[] = $result;
            } else {
                try {
                    $this->runFinishCommand($result);
                } catch (\Exception $e) {
                    $this->logger->warning('[GracefulScheduleWorker] Failed to run finish command', [
                        'event' => $result->getEventIdentifier(),
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
            }
        }
        $this->runningProcesses = $stillRunning;
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

        // Phase 4: Run finish commands for all processes
        foreach ($this->runningProcesses as $result) {
            try {
                $this->runFinishCommand($result);
            } catch (\Exception $e) {
                $this->logger->warning('[GracefulScheduleWorker] Failed to run finish command', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $this->runningProcesses = [];
    }

    /**
     * Run the schedule:finish command for a completed or terminated process.
     *
     * @param StartedLocalDispatchResult $result
     * @return void
     */
    protected function runFinishCommand(StartedLocalDispatchResult $result): void
    {
        $template = $result->getFinishCommandTemplate();
        if ($template === null) {
            return;
        }

        $exitCode = $result->getExitCode() ?? self::EXIT_CODE_SIGTERM;
        $command = $template->buildCommand($exitCode);

        $process = Process::fromShellCommandline($command, $this->basePath);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning('[GracefulScheduleWorker] schedule:finish exited with non-zero status', [
                'event' => $result->getEventIdentifier(),
                'exitCode' => $process->getExitCode(),
                'errorOutput' => $process->getErrorOutput(),
            ]);
        }
    }
}
