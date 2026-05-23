<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\LocalDispatchResultFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResultInterface;
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
     * @var Container
     */
    private $container;

    /**
     * @var RunningProcessManager
     */
    private $processManager;

    /**
     * @var LocalDispatchResultFactory
     */
    private $resultFactory;

    /**
     * @param Container $container Laravel container instance
     * @param string|null $basePath Working directory for processes (null uses the current directory)
     * @param LoggerInterface $logger Logger
     * @param RunningProcessManager $processManager Process lifecycle manager
     * @param LocalDispatchResultFactory $resultFactory Builds Started/Failed/Skipped results
     */
    public function __construct(
        Container $container,
        ?string $basePath,
        LoggerInterface $logger,
        RunningProcessManager $processManager,
        LocalDispatchResultFactory $resultFactory
    ) {
        $this->container = $container;
        $this->basePath = $basePath;
        $this->logger = $logger;
        $this->processManager = $processManager;
        $this->resultFactory = $resultFactory;
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
     * @param \DateTimeImmutable $dispatchedAt Representative dispatch instant from the Orchestrator;
     *        flows into the Result's dispatchedAt unchanged
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt,
        \DateTimeImmutable $dispatchedAt
    ): DispatchResultInterface {
        $identifier = $event->mutexName();
        $mutexAcquired = false;

        try {
            if ($event->withoutOverlapping && !$event->mutex->create($event)) {
                return $this->resultFactory->skipped(
                    $identifier,
                    (string) $event->command,
                    SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
                    $dispatchedAt
                );
            }
            $mutexAcquired = $event->withoutOverlapping;

            $event->callBeforeCallbacks($this->container);

            if ($event->runInBackground) {
                $fullCommand = $event->buildProcessCommand();
                $process = $this->createProcess($fullCommand);
                $process->start();

                $result = $this->resultFactory->started(
                    $process,
                    $identifier,
                    $fullCommand,
                    $dispatchedAt,
                    $event
                );
                $this->processManager->add($result);
                return $result;
            }

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

            return $this->resultFactory->started(
                $process,
                $identifier,
                $fullCommand,
                $dispatchedAt
            );
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

            return $this->resultFactory->failed(
                $identifier,
                $event->command,
                $e,
                $dispatchedAt
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        $this->processManager->cleanup();
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        $this->processManager->stopAll();
    }

    /**
     * @param string $command Shell command string
     * @return Process
     *
     * @SuppressWarnings("PHPMD.StaticAccess") Process::fromShellCommandline is a Symfony factory API
     */
    private function createProcess(string $command): Process
    {
        $process = Process::fromShellCommandline($command, $this->basePath);
        $process->setTimeout(null);

        return $process;
    }
}
