<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedLocalDispatchResult;
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
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var RunningProcessManager
     */
    private $processManager;

    /**
     * @var callable(string, string, \DateTimeImmutable): DispatchResultInterface
     */
    private $skippedResultFactory;

    /**
     * @param Container $container Laravel container instance
     * @param string|null $basePath Working directory for processes (null uses the current directory)
     * @param LoggerInterface $logger Logger
     * @param ClockInterface $clock Clock
     * @param RunningProcessManager $processManager Process lifecycle manager
     * @param callable(string, string, \DateTimeImmutable): DispatchResultInterface $skippedResultFactory
     */
    public function __construct(
        Container $container,
        ?string $basePath,
        LoggerInterface $logger,
        ClockInterface $clock,
        RunningProcessManager $processManager,
        callable $skippedResultFactory
    ) {
        $this->container = $container;
        $this->basePath = $basePath;
        $this->logger = $logger;
        $this->clock = $clock;
        $this->processManager = $processManager;
        $this->skippedResultFactory = $skippedResultFactory;
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
            if ($event->withoutOverlapping && !$event->mutex->create($event)) {
                return ($this->skippedResultFactory)(
                    $identifier,
                    (string) $event->command,
                    $this->clock->now()
                );
            }
            $mutexAcquired = $event->withoutOverlapping;

            $event->callBeforeCallbacks($this->container);

            if ($event->runInBackground) {
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

            return new FailedLocalDispatchResult($identifier, $event->command, $e, $this->clock->now());
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
