<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;

/**
 * Manages the lifecycle of running background processes.
 *
 * Handles process tracking, cleanup of completed processes (with afterCallback execution),
 * and graceful shutdown (SIGTERM → wait → SIGKILL → mutex release).
 */
class RunningProcessManager
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SleeperInterface
     */
    private $sleeper;

    /**
     * @var float
     */
    private $stopTimeout;

    /**
     * @var array<StartedLocalDispatchResult>
     */
    private $runningProcesses = [];

    /**
     * @param Container $container Laravel container instance
     * @param LoggerInterface $logger Logger
     * @param SleeperInterface $sleeper Sleeper (for polling in stopAll)
     * @param float $stopTimeout Timeout in seconds for SIGTERM->SIGKILL wait in stopAll()
     */
    public function __construct(
        Container $container,
        LoggerInterface $logger,
        SleeperInterface $sleeper,
        float $stopTimeout
    ) {
        $this->container = $container;
        $this->logger = $logger;
        $this->sleeper = $sleeper;
        $this->stopTimeout = $stopTimeout;
    }

    /**
     * Add a result to the tracked running processes.
     *
     * @param StartedLocalDispatchResult $result
     * @return void
     */
    public function add(StartedLocalDispatchResult $result): void
    {
        $this->runningProcesses[] = $result;
    }

    /**
     * Clean up completed processes by running their afterCallbacks.
     *
     * Processes that are still running remain in the tracked list.
     *
     * @return void
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
                $result->runAfterCallbacks($this->container);
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
     * Stop all running processes gracefully.
     *
     * Sends SIGTERM, waits for termination up to timeout, then sends SIGKILL
     * to remaining processes. Releases withoutOverlapping mutexes.
     * afterCallbacks are NOT executed (processes were forcefully terminated).
     *
     * @return void
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
}
