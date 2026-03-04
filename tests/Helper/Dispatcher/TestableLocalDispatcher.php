<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;

/**
 * Testable LocalDispatcher
 *
 * Extends LocalDispatcher to allow injecting processes externally via RunningProcessManager.
 */
class TestableLocalDispatcher extends LocalDispatcher
{
    /**
     * @var RunningProcessManager
     */
    private $testProcessManager;

    /**
     * Convenience constructor that creates a RunningProcessManager internally,
     * preserving the existing test call-sites' constructor signatures.
     *
     * @param Container $container
     * @param string|null $basePath
     * @param LoggerInterface $logger
     * @param SleeperInterface $sleeper
     * @param ClockInterface $clock
     * @param float $stopTimeout
     */
    public function __construct(
        Container $container,
        ?string $basePath,
        LoggerInterface $logger,
        SleeperInterface $sleeper,
        ClockInterface $clock,
        float $stopTimeout = 10.0
    ) {
        $processManager = new RunningProcessManager($container, $logger, $sleeper, $stopTimeout);
        $skippedResultFactory = function (
            string $eventIdentifier,
            string $eventCommand,
            \DateTimeImmutable $dispatchedAt
        ) {
            return new SkippedDispatchResult(
                $eventIdentifier,
                $eventCommand,
                'withoutOverlapping',
                $dispatchedAt,
                DispatcherType::LOCAL
            );
        };
        parent::__construct($container, $basePath, $logger, $clock, $processManager, $skippedResultFactory);
        $this->testProcessManager = $processManager;
    }

    /**
     * @param StartedLocalDispatchResult $result
     * @return void
     */
    public function addRunningProcess(StartedLocalDispatchResult $result): void
    {
        $this->testProcessManager->add($result);
    }
}
