<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeFailedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeLockProvider;

/**
 * Smoke test for Orchestrator + TrackingDispatcher + CacheExecutionTracker composition
 *
 * Combines real classes, with FakeCacheStore + FakeDispatcher at the leaf level.
 */
class OrchestratorFlowIntegrationTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $eventMutex;

    /** @var FakeLockProvider */
    private $lockProvider;

    /** @var FakeCacheStore */
    private $cache;

    /** @var SpyLogger */
    private $logger;

    /** @var FakeDispatcher */
    private $innerDispatcher;

    /** @var SpySchedule */
    private $schedule;

    /** @var FakeApplication */
    private $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->eventMutex = new FakeEventMutex();
        $schedulingMutex = new FakeSchedulingMutex();
        $this->lockProvider = new FakeLockProvider();
        $this->cache = new FakeCacheStore($this->lockProvider);
        $this->logger = new SpyLogger();

        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->innerDispatcher = new FakeDispatcher($defaultResult);
        $this->schedule = new SpySchedule($this->eventMutex, $schedulingMutex);
        $this->app = new FakeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @return CacheExecutionTracker
     */
    private function createTracker(): CacheExecutionTracker
    {
        return new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
    }

    /**
     * @param int $maxCalls
     * @return callable
     */
    private function createShouldContinue(int $maxCalls): callable
    {
        $callCount = 0;
        return function () use (&$callCount, $maxCalls) {
            return $callCount++ < $maxCalls;
        };
    }

    /**
     * @testdox TI.1 Normal dispatch: Orchestrator → TrackingDispatcher → FakeDispatcher with markExecuted
     */
    public function testNormalDispatchFlow(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = $this->createTracker();

        $event = new ClockAwareEvent($this->eventMutex, 'echo normal', $clock);
        $event->cron('0 * * * *');

        $this->schedule->setDueEvents([$event]);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // Verify markExecuted record
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertNotNull($this->cache->get($key));
    }

    /**
     * @testdox TI.2 Recovery dispatch: missed event detected → lock → dispatch → markExecuted with correct time
     */
    public function testRecoveryDispatchFlow(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();

        $event = new ClockAwareEvent($this->eventMutex, 'echo recovery', $clock);
        $event->cron('0 * * * *');
        $event->withGracePeriod(120);

        // Last execution recorded at 10:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        // Recovery dispatch is executed
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // Verify the dueAt passed to dispatch is 11:00 (missed due)
        $dispatched = $this->innerDispatcher->getDispatched();
        $dueAt = $dispatched[0]['dueAt'];
        $this->assertSame('11', $dueAt->format('H'));
        $this->assertSame('00', $dueAt->format('i'));

        // markExecuted is recorded with the recovery time (11:00)
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $lastExecuted = new DateTimeImmutable('@' . (int) $this->cache->get($key));
        $this->assertSame('11', $lastExecuted->format('H'));
        $this->assertSame('00', $lastExecuted->format('i'));
    }

    /**
     * @testdox TI.3 Composite routing: dispatchVia routes events to correct dispatcher through Orchestrator
     */
    public function testCompositeRoutingFlow(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = $this->createTracker();

        $localResult = FakeStartedDispatchResult::create('local-id', 'echo local', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'echo sfn', 'stepfunctions');
        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $composite = new CompositeDispatcher(
            ['local' => $localDispatcher, 'stepfunctions' => $sfnDispatcher],
            'local',
            $this->logger
        );
        $trackingDispatcher = new TrackingDispatcher($composite, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $localEvent = new ClockAwareEvent($this->eventMutex, 'echo local', $clock);
        $localEvent->cron('0 * * * *');

        $sfnEvent = new ClockAwareEvent($this->eventMutex, 'echo sfn', $clock);
        $sfnEvent->cron('0 * * * *');
        $sfnEvent->dispatchVia('stepfunctions');

        $this->schedule->setDueEvents([$localEvent, $sfnEvent]);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        $this->assertSame(1, $localDispatcher->getDispatchCount());
        $this->assertSame(1, $sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.20 Recovery after failed dispatch: second worker run recovers the missed event
     */
    public function testRecoveryAfterFailedDispatch(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();

        $event = new ClockAwareEvent($this->eventMutex, 'echo recover-after-fail', $clock);
        $event->cron('0 * * * *');
        $event->withGracePeriod(120);

        // Last execution recorded at 10:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        // First run: recovery detects missed 11:00 but dispatch fails
        $this->innerDispatcher->setResult(
            FakeFailedDispatchResult::create($event->mutexName(), 'echo recover-after-fail', 'StepFunctions error')
        );
        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(0));

        // Recovery was attempted but failed
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());
        // markExecuted was NOT called (still shows 10:00)
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $lastExecuted = new DateTimeImmutable('@' . (int) $this->cache->get($key));
        $this->assertSame('10', $lastExecuted->format('H'));

        // Second run (simulating worker restart): recovery should succeed
        $this->innerDispatcher->setResult(
            FakeStartedDispatchResult::create($event->mutexName(), 'echo recover-after-fail', 'fake')
        );
        $this->innerDispatcher->reset();
        $trackingDispatcher2 = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator2 = new DefaultScheduleOrchestrator(
            $trackingDispatcher2,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator2->run($this->schedule, $this->app, $this->createShouldContinue(0));

        // Recovery succeeded on second run
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // markExecuted is now recorded with 11:00
        $lastExecuted2 = new DateTimeImmutable('@' . (int) $this->cache->get($key));
        $this->assertSame('11', $lastExecuted2->format('H'));
        $this->assertSame('00', $lastExecuted2->format('i'));
    }

    /**
     * @testdox TI.4 Shutdown propagation: shouldContinue=false triggers stopAll on all dispatchers
     */
    public function testShutdownPropagation(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = $this->createTracker();

        $localResult = FakeStartedDispatchResult::create('local-id', 'echo local', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'echo sfn', 'stepfunctions');
        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $composite = new CompositeDispatcher(
            ['local' => $localDispatcher, 'stepfunctions' => $sfnDispatcher],
            'local',
            $this->logger
        );
        $trackingDispatcher = new TrackingDispatcher($composite, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $event = new ClockAwareEvent($this->eventMutex, 'echo shutdown', $clock);
        $event->cron('0 * * * *');
        $this->schedule->setDueEvents([$event]);

        // shouldContinue returns false immediately -> does not enter loop, calls stopAll
        $orchestrator->run($this->schedule, $this->app, function () {
            return false;
        });

        // stopAll propagated to all dispatchers
        $this->assertSame(1, $localDispatcher->getStopAllCallCount());
        $this->assertSame(1, $sfnDispatcher->getStopAllCallCount());
    }
}
