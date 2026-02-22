<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeAlreadyRunningDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeFailedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeSkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\StubThrowingExecutionTracker;

class TrackingDispatcherTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var FakeDispatcher */
    private $innerDispatcher;

    /** @var FakeExecutionTracker */
    private $tracker;

    /** @var SpyLogger */
    private $logger;

    /** @var TrackingDispatcher */
    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->mutex = new FakeEventMutex();
        $this->container->bind(EventMutex::class, function () {
            return $this->mutex;
        });

        $this->tracker = new FakeExecutionTracker();
        $this->logger = new SpyLogger();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock, 'local', null, new TimezoneResolver());
    }

    private function createDispatcher(DispatchResultInterface $resultToReturn): TrackingDispatcher
    {
        $this->innerDispatcher = new FakeDispatcher($resultToReturn);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        return new TrackingDispatcher(
            $this->innerDispatcher,
            $this->tracker,
            $this->logger,
            $clock,
            function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                \DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            }
        );
    }

    /**
     * @testdox TD.1 Delegates to inner Dispatcher when lock is acquired
     */
    public function testDelegatesToInnerDispatcherWhenLockAcquired(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertSame($startedResult, $result);
        $this->assertCount(1, $this->innerDispatcher->getDispatched());
        $dispatched = $this->innerDispatcher->getDispatched()[0];
        $this->assertSame($event, $dispatched['event']);
        $this->assertSame($dueAt, $dispatched['dueAt']);
    }

    /**
     * @testdox TD.2 Returns SkippedDispatchResult when lock is not acquired
     */
    public function testReturnsSkippedWhenLockNotAcquired(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // Set lock acquisition to fail
        $this->tracker->setLockResult($event->mutexName(), $dueAt, false);

        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(SkippedDispatchResult::class, $result);
        $this->assertSame('lock_not_acquired', $result->getReason());
        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
        $this->assertSame('echo test', $result->getEventCommand());

        // Inner Dispatcher is not called
        $this->assertCount(0, $this->innerDispatcher->getDispatched());
    }

    /**
     * @testdox TD.3 markExecuted is called on Started result
     */
    public function testMarksExecutedOnStartedResult(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $dueAt);

        $executed = $this->tracker->getExecuted();
        $this->assertArrayHasKey($event->mutexName(), $executed);
        $this->assertSame($dueAt, $executed[$event->mutexName()]);
    }

    /**
     * @testdox TD.4 markExecuted is called on AlreadyRunning result
     */
    public function testMarksExecutedOnAlreadyRunningResult(): void
    {
        $alreadyRunningResult = FakeAlreadyRunningDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($alreadyRunningResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $dueAt);

        $executed = $this->tracker->getExecuted();
        $this->assertArrayHasKey($event->mutexName(), $executed);
        $this->assertSame($dueAt, $executed[$event->mutexName()]);
    }

    /**
     * @testdox TD.5 Error log is output on Failed result
     */
    public function testLogsErrorOnFailedResult(): void
    {
        $failedResult = FakeFailedDispatchResult::create(
            'test-mutex',
            'echo test',
            'Test error message',
            'fake'
        );
        $dispatcher = $this->createDispatcher($failedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $dueAt);

        // markExecuted is not called
        $executed = $this->tracker->getExecuted();
        $this->assertArrayNotHasKey($event->mutexName(), $executed);

        // Error log is output
        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertStringContainsString('Failed to dispatch event', $errorLogs[0]['message']);
        $this->assertSame($event->mutexName(), $errorLogs[0]['context']['event']);
        $this->assertSame('Test error message', $errorLogs[0]['context']['error']);
    }

    /**
     * @testdox TD.6 Exception is included in context when Failed result has one
     */
    public function testLogsExceptionInContextOnFailedResult(): void
    {
        $exception = new \RuntimeException('Test exception');
        $failedResult = FakeFailedDispatchResult::create(
            'test-mutex',
            'echo test',
            'Test error message',
            'fake',
            $exception
        );
        $dispatcher = $this->createDispatcher($failedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $dueAt);

        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertSame($exception, $errorLogs[0]['context']['exception']);
    }

    /**
     * @testdox TD.7 Exception is thrown on unexpected result type
     */
    public function testThrowsExceptionOnUnexpectedResultType(): void
    {
        // Class that implements DispatchResultInterface but not any known sub-interface
        $unexpectedResult = new class implements DispatchResultInterface {
            public function getEventIdentifier(): string
            {
                return 'test';
            }

            public function getEventCommand(): string
            {
                return 'test';
            }

            public function getDispatcherType(): string
            {
                return 'unexpected';
            }

            public function getDispatchedAt(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };

        $this->innerDispatcher = new FakeDispatcher($unexpectedResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TrackingDispatcher(
            $this->innerDispatcher,
            $this->tracker,
            $this->logger,
            $clock,
            function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                \DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            }
        );
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unexpected dispatch result type');

        $dispatcher->dispatchEvent($event, $dueAt);
    }

    /**
     * @testdox TD.8 cleanup delegates to inner Dispatcher
     */
    public function testCleanupDelegatesToInnerDispatcher(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);

        $dispatcher->cleanup();

        $this->assertSame(1, $this->innerDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox TD.9 stopAll delegates to inner Dispatcher
     */
    public function testStopAllDelegatesToInnerDispatcher(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);

        $dispatcher->stopAll();

        $this->assertSame(1, $this->innerDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox TD.10 SkippedDispatchResult dispatcherType returns 'local' for plain Event
     */
    public function testSkippedDispatchResultReturnsLocalTypeForPlainEvent(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // Set lock acquisition to fail
        $this->tracker->setLockResult($event->mutexName(), $dueAt, false);

        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox TD.11 DEBUG log is output when lock is not acquired
     */
    public function testLogsDebugWhenLockNotAcquired(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // Set lock acquisition to fail
        $this->tracker->setLockResult($event->mutexName(), $dueAt, false);

        $dispatcher->dispatchEvent($event, $dueAt);

        // DEBUG log is output
        $debugLogs = $this->logger->getLogsByLevel('debug');
        $this->assertCount(1, $debugLogs);
        $this->assertStringContainsString('Lock not acquired, skipping dispatch', $debugLogs[0]['message']);
        $this->assertSame($event->mutexName(), $debugLogs[0]['context']['event']);
        $this->assertSame('2024-01-15T10:00:00+00:00', $debugLogs[0]['context']['dueAt']);
    }

    /**
     * @testdox TD.12 markExecuted exception is caught and error log is output
     */
    public function testCatchesMarkExecutedExceptionAndLogsError(): void
    {
        $exception = new \RuntimeException('Redis connection lost');
        $throwingTracker = new StubThrowingExecutionTracker($exception);
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $innerDispatcher = new FakeDispatcher($startedResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TrackingDispatcher(
            $innerDispatcher,
            $throwingTracker,
            $this->logger,
            $clock,
            function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                \DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            }
        );

        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // No exception is thrown and the result is returned
        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertSame($startedResult, $result);

        // Error log is output
        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertStringContainsString('Failed to track execution result', $errorLogs[0]['message']);
        $this->assertSame($event->mutexName(), $errorLogs[0]['context']['event']);
        $this->assertSame('Redis connection lost', $errorLogs[0]['context']['error']);
        $this->assertSame($exception, $errorLogs[0]['context']['exception']);
    }

    /**
     * @testdox TD.14 Info log is output on Started result
     */
    public function testLogsInfoOnStartedResult(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $dueAt);

        $infoLogs = $this->logger->getLogsByLevel('info');
        $this->assertCount(1, $infoLogs);
        $this->assertStringContainsString('Event dispatched', $infoLogs[0]['message']);
        $this->assertSame($event->mutexName(), $infoLogs[0]['context']['event']);
        $this->assertSame('fake', $infoLogs[0]['context']['dispatcher_type']);
        $this->assertSame('2024-01-15T10:00:00+00:00', $infoLogs[0]['context']['dueAt']);
    }

    /**
     * @testdox TD.15 Info log is output on AlreadyRunning result
     */
    public function testLogsInfoOnAlreadyRunningResult(): void
    {
        $alreadyRunningResult = FakeAlreadyRunningDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($alreadyRunningResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $dueAt);

        $infoLogs = $this->logger->getLogsByLevel('info');
        $this->assertCount(1, $infoLogs);
        $this->assertStringContainsString('Event already running, skipped new execution', $infoLogs[0]['message']);
        $this->assertSame($event->mutexName(), $infoLogs[0]['context']['event']);
        $this->assertSame('fake', $infoLogs[0]['context']['dispatcher_type']);
        $this->assertSame('2024-01-15T10:00:00+00:00', $infoLogs[0]['context']['dueAt']);
    }

    /**
     * @testdox TD.13 LogicException from tracking is caught and logged (not rethrown)
     */
    public function testLogicExceptionFromTrackingIsCaughtAndLogged(): void
    {
        // Tracker that throws LogicException
        $logicException = new \LogicException('Programming error');
        $throwingTracker = new StubThrowingExecutionTracker($logicException);
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $innerDispatcher = new FakeDispatcher($startedResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TrackingDispatcher(
            $innerDispatcher,
            $throwingTracker,
            $this->logger,
            $clock,
            function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                \DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            }
        );

        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // LogicException is caught and logged, not rethrown
        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertSame($startedResult, $result);

        // Error log is output
        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertStringContainsString('Failed to track execution result', $errorLogs[0]['message']);
        $this->assertSame('Programming error', $errorLogs[0]['context']['error']);
    }

    /**
     * @testdox TD.16 handleResult handles SkippedDispatchResult from inner dispatcher
     */
    public function testHandleResultHandlesSkippedDispatchResultFromInnerDispatcher(): void
    {
        $skippedResult = FakeSkippedDispatchResult::create('test-mutex', 'echo test', 'withoutOverlapping', 'local');
        $this->innerDispatcher = new FakeDispatcher($skippedResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TrackingDispatcher(
            $this->innerDispatcher,
            $this->tracker,
            $this->logger,
            $clock,
            function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                \DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            }
        );
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $result = $dispatcher->dispatchEvent($event, $dueAt);

        // SkippedDispatchResult is returned from inner dispatcher
        $this->assertSame($skippedResult, $result);

        // releaseLock is called
        $locks = $this->tracker->getLocks();
        $key = $event->mutexName() . ':' . $dueAt->getTimestamp();
        $this->assertArrayNotHasKey($key, $locks);

        // Info log is output
        $infoLogs = $this->logger->getLogsByLevel('info');
        $this->assertCount(1, $infoLogs);
        $this->assertStringContainsString('Event skipped by inner dispatcher', $infoLogs[0]['message']);
        $this->assertSame($event->mutexName(), $infoLogs[0]['context']['event']);
        $this->assertSame('local', $infoLogs[0]['context']['dispatcher_type']);
        $this->assertSame('withoutOverlapping', $infoLogs[0]['context']['reason']);
    }

    /**
     * @testdox TD.18 releaseLock is called on Failed result
     */
    public function testReleasesLockOnFailedResult(): void
    {
        $failedResult = FakeFailedDispatchResult::create(
            'test-mutex',
            'echo test',
            'StepFunctions error',
            'stepfunctions'
        );
        $dispatcher = $this->createDispatcher($failedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $dueAt);

        // Lock should be released since dispatch failed (task was not executed)
        $locks = $this->tracker->getLocks();
        $key = $event->mutexName() . ':' . $dueAt->getTimestamp();
        $this->assertArrayNotHasKey($key, $locks, 'Lock should be released on FailedDispatchResult');

        // markExecuted should NOT be called
        $executed = $this->tracker->getExecuted();
        $this->assertArrayNotHasKey($event->mutexName(), $executed);
    }

    /**
     * @testdox TD.17 SkippedDispatchResult uses ClockAwareEvent dispatcherType
     */
    public function testSkippedDispatchResultUsesClockAwareEventDispatcherType(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'echo test', $clock, 'stepfunctions', null, new TimezoneResolver());
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // Set lock acquisition to fail
        $this->tracker->setLockResult($event->mutexName(), $dueAt, false);

        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox TD.19 releaseLock exception on Skipped result is caught and logged
     */
    public function testReleaseLockExceptionOnSkippedResultIsCaughtAndLogged(): void
    {
        $skippedResult = FakeSkippedDispatchResult::create('test-mutex', 'echo test', 'withoutOverlapping', 'local');
        $this->innerDispatcher = new FakeDispatcher($skippedResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $this->tracker->setReleaseLockException(new \RuntimeException('Redis connection lost'));
        $dispatcher = new TrackingDispatcher(
            $this->innerDispatcher,
            $this->tracker,
            $this->logger,
            $clock,
            function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                \DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            }
        );
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // No exception is thrown
        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertSame($skippedResult, $result);

        // Error log for releaseLock failure
        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertStringContainsString('Failed to release lock', $errorLogs[0]['message']);
        $this->assertSame($event->mutexName(), $errorLogs[0]['context']['event']);
        $this->assertSame('Redis connection lost', $errorLogs[0]['context']['error']);
    }

    /**
     * @testdox TD.20 releaseLock exception on Failed result is caught and handleDispatchFailure still runs
     */
    public function testReleaseLockExceptionOnFailedResultIsCaughtAndHandleDispatchFailureStillRuns(): void
    {
        $failedResult = FakeFailedDispatchResult::create(
            'test-mutex',
            'echo test',
            'StepFunctions error',
            'stepfunctions'
        );
        $this->innerDispatcher = new FakeDispatcher($failedResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $this->tracker->setReleaseLockException(new \RuntimeException('Redis connection lost'));
        $dispatcher = new TrackingDispatcher(
            $this->innerDispatcher,
            $this->tracker,
            $this->logger,
            $clock,
            function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                \DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            }
        );
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // No exception is thrown
        $result = $dispatcher->dispatchEvent($event, $dueAt);

        $this->assertSame($failedResult, $result);

        // Both releaseLock error and dispatch failure error are logged
        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(2, $errorLogs);
        $this->assertStringContainsString('Failed to release lock', $errorLogs[0]['message']);
        $this->assertStringContainsString('Failed to dispatch event', $errorLogs[1]['message']);
    }
}
