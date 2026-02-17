<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\ExceptionFormatter;
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
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var float
     */
    private $stopTimeout;

    /**
     * @var Container
     */
    private $container;

    /**
     * @param Container $container Laravel container instance
     * @param string|null $basePath Working directory for processes (null uses the current directory)
     * @param LoggerInterface $logger Logger
     * @param SleeperInterface $sleeper Sleeper (for polling in stopAll)
     * @param ClockInterface $clock Clock
     * @param float $stopTimeout Timeout in seconds for SIGTERM->SIGKILL wait in stopAll()
     */
    public function __construct(
        Container $container,
        ?string $basePath,
        LoggerInterface $logger,
        SleeperInterface $sleeper,
        ClockInterface $clock,
        float $stopTimeout = 10.0
    ) {
        $this->container = $container;
        $this->basePath = $basePath;
        $this->logger = $logger;
        $this->sleeper = $sleeper;
        $this->clock = $clock;
        $this->stopTimeout = $stopTimeout;
    }

    /**
     * Dispatch a single event.
     *
     * Respects the Event's runInBackground setting and selects the appropriate execution path.
     * - beforeCallbacks are executed synchronously in the parent process
     * - runInBackground = true: uses buildProcessCommand() with exec, runs async via Process::start()
     *   (afterCallbacks are handled by cleanup; stopAll only releases mutexes)
     * - runInBackground = false: runs synchronously via buildCommand() and calls afterCallbacks directly
     *
     * @param ClockAwareEvent $event The schedule event to execute
     * @param DateTimeInterface $dueAt Scheduled due time (unused in LocalDispatcher)
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt
    ): DispatchResultInterface {
        $identifier = $event->mutexName();
        $mutexAcquired = false;

        try {
            // Handle withoutOverlapping: atomically try to claim the mutex
            if ($event->withoutOverlapping && !$event->mutex->create($event)) {
                return new SkippedDispatchResult(
                    $identifier,
                    (string) $event->command,
                    'withoutOverlapping',
                    $this->clock->now(),
                    DispatcherType::LOCAL
                );
            }
            $mutexAcquired = $event->withoutOverlapping;

            $event->callBeforeCallbacks($this->container);

            if ($event->runInBackground) {
                // Background: generate exec command, run async
                $fullCommand = $event->buildProcessCommand();
                $process = $this->createProcess($fullCommand);
                $process->start();

                $result = new StartedLocalDispatchResult(
                    $process,
                    $identifier,
                    $fullCommand,
                    $this->clock->now(),
                    $event
                );
                $this->runningProcesses[] = $result;
                return $result;
            }

            // Foreground: run command synchronously and call afterCallbacks directly
            $fullCommand = $event->buildCommand();
            $process = $this->createProcess($fullCommand);
            $process->run();

            try {
                $event->callAfterCallbacksWithExitCode($this->container, (int) $process->getExitCode());
            } catch (\Exception $e) {
                // afterCallback failures don't affect the dispatch result.
                // \Error is not caught here; it propagates to the command-level handler.
                $this->logger->warning('afterCallback failed', [
                    'event' => $identifier,
                    'exitCode' => $process->getExitCode(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }

            return new StartedLocalDispatchResult($process, $identifier, $fullCommand, $this->clock->now());
        } catch (\Exception $e) {
            // Note: \Error is not caught (fatal errors propagate to the caller)
            if ($mutexAcquired) {
                try {
                    $event->mutex->forget($event);
                } catch (\Exception $mutexException) {
                    $this->logger->warning('Failed to release EventMutex after dispatch failure', [
                        'event' => $identifier,
                        'error' => $mutexException->getMessage(),
                        'exception' => $mutexException,
                    ]);
                }
            }

            $error = ExceptionFormatter::format($e);

            return new FailedLocalDispatchResult($identifier, $event->command, $error, $e, $this->clock->now());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        $pending = $this->runningProcesses;
        $this->runningProcesses = [];

        foreach ($pending as $result) {
            if ($result->isRunning()) {
                $this->runningProcesses[] = $result;
                continue;
            }
            try {
                $this->runAfterCallbacksForResult($result);
            } catch (\Exception $e) {
                $this->logger->warning('afterCallback failed during cleanup', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        $this->sendSignalToAll(SIGTERM, 'SIGTERM', 'warning');
        $this->waitForTermination();
        $this->sendSignalToAll(SIGKILL, 'SIGKILL', 'error');
        $processes = $this->runningProcesses;
        $this->runningProcesses = [];
        $this->releaseMutexes($processes);
    }

    /**
     * Send a signal to all running processes.
     *
     * @param int $signal Signal number
     * @param string $signalName Signal name for logging
     * @param string $logLevel PSR log level for failures
     * @return void
     */
    private function sendSignalToAll(int $signal, string $signalName, string $logLevel): void
    {
        foreach ($this->runningProcesses as $result) {
            try {
                $process = $result->getProcess();
                if ($process->isRunning()) {
                    $process->signal($signal);
                }
            } catch (\Exception $e) {
                $this->logger->log($logLevel, "Failed to send {$signalName}", [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Poll and wait for all processes to terminate until timeout.
     *
     * @return void
     */
    private function waitForTermination(): void
    {
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
    }

    /**
     * Release withoutOverlapping mutexes for the given processes.
     *
     * During stopAll (shutdown), only mutex release is performed.
     * User-registered afterCallbacks are not executed because the
     * processes were forcefully terminated, not completed normally.
     *
     * @param array<StartedLocalDispatchResult> $processes
     * @return void
     */
    private function releaseMutexes(array $processes): void
    {
        foreach ($processes as $result) {
            $event = $result->getEvent();
            if ($event === null || !$event->withoutOverlapping) {
                continue;
            }
            try {
                $event->mutex->forget($event);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to release EventMutex during stopAll', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * @param string $command Shell command string
     * @return Process
     */
    private function createProcess(string $command): Process
    {
        $process = Process::fromShellCommandline($command, $this->basePath);
        $process->setTimeout(null);

        return $process;
    }

    /**
     * Run afterCallbacks for a completed or terminated process.
     *
     * @param StartedLocalDispatchResult $result
     * @return void
     */
    private function runAfterCallbacksForResult(StartedLocalDispatchResult $result): void
    {
        $result->runAfterCallbacks($this->container);
    }
}
