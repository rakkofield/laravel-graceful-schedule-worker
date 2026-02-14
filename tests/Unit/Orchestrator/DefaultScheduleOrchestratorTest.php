<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ThrowingFakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

/**
 * Unit tests for DefaultScheduleOrchestrator
 *
 * Note: Lock acquisition, markExecuted, and failure handling are TrackingDispatcher's responsibility
 *       Already tested in TrackingDispatcherTest
 */
class DefaultScheduleOrchestratorTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $eventMutex;

    /** @var FakeSchedulingMutex */
    private $schedulingMutex;

    /** @var FakeDispatcher */
    private $dispatcher;

    /** @var SpySchedule */
    private $schedule;

    /** @var FakeApplication */
    private $app;

    /** @var FixedClock */
    private $clock;

    /** @var NullLogger */
    private $logger;

    /** @var NullSleeper */
    private $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->eventMutex = new FakeEventMutex();
        $this->schedulingMutex = new FakeSchedulingMutex();

        // Fix time to 12:00:00 (seconds at 0)
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->dispatcher = new FakeDispatcher($defaultResult);
        $this->schedule = new SpySchedule($this->eventMutex, $this->schedulingMutex, $this->clock);
        $this->app = new FakeApplication();
        $this->logger = new NullLogger();
        $this->sleeper = new NullSleeper();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param string $command
     * @return ClockAwareEvent
     */
    private function createEvent(string $command): ClockAwareEvent
    {
        return new ClockAwareEvent($this->eventMutex, $command, $this->clock);
    }

    /**
     * @param ExecutionTrackerInterface|null $tracker
     * @param LoggerInterface|null $logger
     * @return DefaultScheduleOrchestrator
     */
    private function createOrchestrator(
        $tracker = null,
        $logger = null
    ): DefaultScheduleOrchestrator {
        return new DefaultScheduleOrchestrator(
            $this->dispatcher,
            $this->clock,
            $tracker ?? new NullExecutionTracker(),
            $logger ?? $this->logger,
            $this->sleeper
        );
    }

    /**
     * @testdox DO.1 run_executes_due_events
     */
    public function testRunExecutesDueEvents(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event, $dispatched[0]['event']);
    }

    /**
     * @testdox DO.2 run_skips_non_due_events
     */
    public function testRunSkipsNonDueEvents(): void
    {
        // Non-due events are not included in dueEvents, so set empty array
        $this->schedule->setDueEvents([]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.3 run_calls_dispatcher_dispatchEvent_for_each_event
     */
    public function testRunCallsDispatcherDispatchEventForEachEvent(): void
    {
        $event1 = $this->createEvent('echo test1');
        $event2 = $this->createEvent('echo test2');
        $event3 = $this->createEvent('echo test3');
        $this->schedule->setDueEvents([$event1, $event2, $event3]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(3, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event1, $dispatched[0]['event']);
        $this->assertSame($event2, $dispatched[1]['event']);
        $this->assertSame($event3, $dispatched[2]['event']);
    }

    /**
     * @testdox DO.4 run_stops_when_shouldContinue_false
     */
    public function testRunStopsWhenShouldContinueFalse(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();

        // Returns false from the start
        $shouldContinue = function () {
            return false;
        };

        $result = $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // If shouldContinue is false, exit immediately and no events are dispatched
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
        $this->assertTrue($result);
    }

    /**
     * @testdox DO.5 run returns true on success
     */
    public function testRunReturnsTrueOnSuccess(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertTrue($result);
    }

    /**
     * @testdox DO.6 run manages LocalDispatchResult
     */
    public function testRunManagesLocalDispatchResults(): void
    {
        // Set up to return LocalDispatchResult
        // Use fake instead of real process for verification
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'local');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Verify it was dispatched successfully
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.7 dispatcher stopAll is called after run completes
     */
    public function testStopAllIsCalledOnDispatcherWhenOrchestratorStops(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Verify dispatcher's stopAll() is called after run() completes
        $this->assertSame(1, $this->dispatcher->getStopAllCallCount());
    }

    /**
     * @testdox DO.8 dispatcher cleanup is called in each loop iteration
     */
    public function testCleanupIsCalledOnDispatcherInEachLoopIteration(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(3));

        // Verify cleanup() is called in each loop (3 times)
        $this->assertSame(3, $this->dispatcher->getCleanupCallCount());
    }

    /**
     * @testdox DO.9 Only dispatches once per minute even with multiple loops
     */
    public function testOnlyDispatchesOncePerMinute(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // Time is fixed at second 0 of each minute (set to 12:00:00 in setUp)
        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(3));

        // Even with 3 loops, only dispatched once since within the same minute
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.10 Skips dispatch when seconds are not zero
     */
    public function testSkipsDispatchWhenSecondIsNotZero(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // Set seconds to 30 (not 0, so dispatch is skipped)
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:30'));
        $orchestrator = new DefaultScheduleOrchestrator(
            $this->dispatcher,
            $clock,
            new NullExecutionTracker(),
            $this->logger,
            $this->sleeper
        );

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        // Dispatch is not called because seconds are not 0
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.11 dueAt is passed to dispatchEvent
     */
    public function testPassesDueAtToDispatcher(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $dispatched = $this->dispatcher->getDispatched();
        $this->assertCount(1, $dispatched);

        // Verify dueAt is passed
        $dueAt = $dispatched[0]['dueAt'];
        $this->assertInstanceOf(\DateTimeInterface::class, $dueAt);
        // Verify the minute matches (seconds are normalized to 0)
        $this->assertSame('2024-01-15 12:00:00', $dueAt->format('Y-m-d H:i:s'));
    }

    /**
     * @testdox DO.12 Checks missed executions at startup and recovers
     */
    public function testChecksMissedExecutionsAtStartupAndRecovers(): void
    {
        $tracker = new FakeExecutionTracker();

        // Create a recoverable event that is not due
        $event = $this->createEvent('echo test');
        $event->cron('0 * * * *'); // Every hour at minute 0
        $event->enableRecovery();  // Enable recovery

        // Due events are empty (no normal dispatch)
        $this->schedule->setDueEvents([]);
        // Included in schedule.events()
        $this->schedule->addEvent($event);

        // Set missed execution: return missedDue
        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Verify dispatched via recovery
        $this->assertSame(1, $this->dispatcher->getDispatchCount());

        // Verify dueAt is missedDue
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($missedDue->getTimestamp(), $dispatched[0]['dueAt']->getTimestamp());
    }

    /**
     * @testdox DO.13 Skips missed event when getMissedDueIfRecoverable returns null
     */
    public function testSkipsMissedEventWhenGetMissedDueIfRecoverableReturnsNull(): void
    {
        $tracker = new FakeExecutionTracker();

        // Create a recoverable event
        $event = $this->createEvent('echo test');
        $event->cron('0 * * * *'); // Every hour at minute 0
        $event->enableRecovery();

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        // No missed execution (returns null)
        $tracker->setRecoverableResult($event->mutexName(), null);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Verify not dispatched because no missed execution
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.14 Does not recover non-recoverable event
     */
    public function testDoesNotRecoverNonRecoverableEvent(): void
    {
        $tracker = new FakeExecutionTracker();

        // Non-recoverable event
        $event = $this->createEvent('echo test');
        $event->cron('0 * * * *'); // Every hour at minute 0
        // Do not call enableRecovery()

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        // Set missed execution (but not checked since event is not recoverable)
        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Verify not dispatched because event is not recoverable
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.15 Logs info when recovering missed event
     */
    public function testLogsInfoWhenRecoveringMissedEvent(): void
    {
        $tracker = new FakeExecutionTracker();

        // Set up an event eligible for recovery
        $event = $this->createEvent('echo test');
        $event->cron('0 * * * *');
        $event->enableRecovery();

        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $spyLogger = new SpyLogger();
        $orchestrator = $this->createOrchestrator($tracker, $spyLogger);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Verify info log is output
        $infoLogs = $spyLogger->getLogsByLevel('info');
        $this->assertNotEmpty($infoLogs);
        $this->assertStringContainsString('Recovering missed event', $infoLogs[0]['message']);
    }

    /**
     * @param int $maxCalls
     * @return callable
     */
    private function createShouldContinue(int $maxCalls = 1): callable
    {
        $callCount = 0;
        return function () use (&$callCount, $maxCalls) {
            return $callCount++ < $maxCalls;
        };
    }

    /**
     * @testdox DO.16 filtersPass when(true) → event is dispatched
     */
    public function testFiltersPassWhenTrueEventIsDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->when(function () {
            return true;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.17 filtersPass when(false) → event is not dispatched
     */
    public function testFiltersPassWhenFalseEventIsNotDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->when(function () {
            return false;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.18 filtersPass skip(true) → event is not dispatched
     */
    public function testFiltersPassSkipTrueEventIsNotDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->skip(function () {
            return true;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.19 filtersPass skip(false) → event is dispatched
     */
    public function testFiltersPassSkipFalseEventIsDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->skip(function () {
            return false;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.20 filtersPass only dispatches events that pass filters
     */
    public function testFiltersPassOnlyDispatchesPassingEvents(): void
    {
        $event1 = $this->createEvent('echo pass');
        $event1->when(function () {
            return true;
        });

        $event2 = $this->createEvent('echo fail');
        $event2->when(function () {
            return false;
        });

        $event3 = $this->createEvent('echo skip');
        $event3->skip(function () {
            return true;
        });

        $this->schedule->setDueEvents([$event1, $event2, $event3]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event1, $dispatched[0]['event']);
    }

    /**
     * @testdox DO.21 Recovery does not check filtersPass
     */
    public function testRecoveryDoesNotCheckFiltersPass(): void
    {
        $tracker = new FakeExecutionTracker();

        $event = $this->createEvent('echo test');
        $event->cron('0 * * * *');
        $event->enableRecovery();
        // Set when(false) -> filtersPass returns false
        $event->when(function () {
            return false;
        });

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Recovery does not call filtersPass, so the event is dispatched
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.22 filtersPass exception skips event and continues
     */
    public function testFiltersPassExceptionSkipsEventAndContinues(): void
    {
        $eventThatThrows = $this->createEvent('echo throw');
        $eventThatThrows->when(function () {
            throw new \RuntimeException('filter error');
        });

        $eventThatPasses = $this->createEvent('echo pass');
        $eventThatPasses->when(function () {
            return true;
        });

        $this->schedule->setDueEvents([$eventThatThrows, $eventThatPasses]);

        $spyLogger = new SpyLogger();
        $orchestrator = $this->createOrchestrator(null, $spyLogger);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // The exception-throwing event is skipped; only the normal event is dispatched
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($eventThatPasses, $dispatched[0]['event']);

        // Verify an error log is output
        $this->assertTrue($spyLogger->hasLogContaining('error', 'filtersPass threw exception'));
    }

    /**
     * @testdox DO.23 filtersPass with Closure condition that returns false → not dispatched
     */
    public function testFiltersPassWithClosureCondition(): void
    {
        $event = $this->createEvent('echo test');
        $event->everyMinute();
        $event->when(function () {
            return false;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.25 checkMissedExecutions continues when one event throws
     */
    public function testCheckMissedExecutionsContinuesWhenOneEventThrows(): void
    {
        $tracker = new FakeExecutionTracker();

        // Event 1: getMissedDueIfRecoverable throws
        $event1 = $this->createEvent('echo event1');
        $event1->cron('0 * * * *');
        $event1->enableRecovery();
        $tracker->setRecoverableException($event1->mutexName(), new \RuntimeException('cron parse error'));

        // Event 2: normal recovery
        $event2 = $this->createEvent('echo event2');
        $event2->cron('0 * * * *');
        $event2->enableRecovery();
        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event2->mutexName(), $missedDue);

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event1);
        $this->schedule->addEvent($event2);

        $result = FakeStartedDispatchResult::create($event2->mutexName(), 'echo event2', 'fake');
        $this->dispatcher->setResult($result);

        $spyLogger = new SpyLogger();
        $orchestrator = $this->createOrchestrator($tracker, $spyLogger);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Event 2 was dispatched despite event 1 throwing
        $this->assertSame(1, $this->dispatcher->getDispatchCount());

        // Warning log for event 1
        $warningLogs = $spyLogger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('Failed to check/recover missed event', $warningLogs[0]['message']);
        $this->assertSame('cron parse error', $warningLogs[0]['context']['error']);
    }

    /**
     * @testdox DO.26 evaluateAt is always called during dispatch
     */
    public function testEvaluateAtIsAlwaysCalledDuringDispatch(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->schedule->getEvaluateAtCallCount());
    }

    /**
     * @testdox DO.27 stopAll is called even when dispatchEvent throws
     */
    public function testStopAllIsCalledEvenWhenDispatchEventThrows(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $defaultResult = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $throwingDispatcher = new ThrowingFakeDispatcher($defaultResult);
        $throwingDispatcher->willThrowOnDispatch(new \RuntimeException('dispatch failed'));

        $orchestrator = new DefaultScheduleOrchestrator(
            $throwingDispatcher,
            $this->clock,
            new NullExecutionTracker(),
            $this->logger,
            $this->sleeper
        );

        try {
            $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('dispatch failed', $e->getMessage());
        }

        // stopAll is called even after exception
        $this->assertSame(1, $throwingDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox DO.24 filtersPass exception logs error with details
     */
    public function testFiltersPassExceptionLogsError(): void
    {
        $event = $this->createEvent('echo throw');
        $event->when(function () {
            throw new \RuntimeException('custom filter error');
        });

        $this->schedule->setDueEvents([$event]);

        $spyLogger = new SpyLogger();
        $orchestrator = $this->createOrchestrator(null, $spyLogger);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // Event is skipped
        $this->assertSame(0, $this->dispatcher->getDispatchCount());

        // Verify the error log contents
        $errorLogs = $spyLogger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertStringContainsString('filtersPass threw exception', $errorLogs[0]['message']);
        $this->assertSame('custom filter error', $errorLogs[0]['context']['error']);
        $this->assertInstanceOf(\RuntimeException::class, $errorLogs[0]['context']['exception']);
    }
}
