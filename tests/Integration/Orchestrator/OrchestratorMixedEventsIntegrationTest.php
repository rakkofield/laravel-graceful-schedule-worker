<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeLockProvider;

/**
 * Mixed native Event and ClockAwareEvent test in Orchestrator pipeline
 *
 * Registers native Events via real ClockAwareSchedule + withNativeEvents(), and verifies
 * that both types are processed correctly in the
 * DefaultScheduleOrchestrator -> TrackingDispatcher -> FakeDispatcher pipeline.
 *
 * Composition: Orchestrator -> TrackingDispatcher -> FakeDispatcher
 *              + CacheExecutionTracker + FakeCacheStore + FakeLockProvider
 */
class OrchestratorMixedEventsIntegrationTest extends TestCase
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

    /** @var FakeApplication */
    private $app;

    /** @var FixedClock */
    private $clock;

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
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));

        $this->container->instance(EventMutex::class, $this->eventMutex);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);

        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->innerDispatcher = new FakeDispatcher($defaultResult);
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
     * @return DefaultScheduleOrchestrator
     */
    private function createOrchestrator(): DefaultScheduleOrchestrator
    {
        $tracker = $this->createTracker();
        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);

        return new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $this->clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );
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
     * @testdox TI.16 Mixed events: both native Event and ClockAwareEvent are dispatched when due
     */
    public function testMixedEventsAreBothDispatched(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('echo native')->everyMinute();
        });

        $schedule->exec('echo graceful')->everyMinute();

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(2, $this->innerDispatcher->getDispatchCount());

        $dispatched = $this->innerDispatcher->getDispatched();
        // Registered inside withNativeEvents -> native Event
        $this->assertNotInstanceOf(ClockAwareEvent::class, $dispatched[0]['event']);
        $this->assertInstanceOf(Event::class, $dispatched[0]['event']);
        // Registered in normal mode -> ClockAwareEvent
        $this->assertInstanceOf(ClockAwareEvent::class, $dispatched[1]['event']);
    }

    /**
     * @testdox TI.17 Filters work correctly for both native Event and ClockAwareEvent
     */
    public function testFiltersPassWorksForBothEventTypes(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('echo native-skip')->everyMinute()->when(function () {
                return false;
            });
            $schedule->exec('echo native-pass')->everyMinute()->when(function () {
                return true;
            });
        });

        $schedule->exec('echo graceful-skip')->everyMinute()->skip(function () {
            return true;
        });
        $schedule->exec('echo graceful-pass')->everyMinute();

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(2, $this->innerDispatcher->getDispatchCount());

        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertStringContainsString('native-pass', (string) $dispatched[0]['event']->command);
        $this->assertStringContainsString('graceful-pass', (string) $dispatched[1]['event']->command);
    }

    /**
     * @testdox TI.18 Recovery skips native Event and recovers only ClockAwareEvent
     */
    public function testRecoverySkipsNativeEventAndRecoversClockAwareEvent(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('echo native-every5')->cron('*/5 * * * *');
        });

        $schedule->exec('echo graceful-every5')->cron('*/5 * * * *')->withGracePeriod(30);

        // Set last execution record (executed at 11:50:00 -> 11:55:00 was missed)
        $tracker = $this->createTracker();
        $events = $schedule->events();

        // Set lastExecutedDue = 11:50:00 for both events
        $lastExecutedDue = new DateTimeImmutable('2024-01-15 11:50:00');
        foreach ($events as $event) {
            $tracker->markExecuted($event, $lastExecutedDue);
        }

        // Re-configure Orchestrator (using the same cache/lockProvider)
        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $this->clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        // shouldContinue(0) -> does not enter loop, only checkMissedExecutions runs
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(0));

        // Native Event is not recoverable (isRecoverableEvent = false)
        // Only ClockAwareEvent is dispatched via recovery
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertInstanceOf(ClockAwareEvent::class, $dispatched[0]['event']);
        $this->assertStringContainsString('graceful-every5', (string) $dispatched[0]['event']->command);
    }

    /**
     * @testdox TI.19 TrackingDispatcher acquires lock and tracks native Event with default TTL
     */
    public function testTrackingDispatcherLocksAndTracksNativeEvent(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('echo native')->everyMinute();
        });

        $tracker = $this->createTracker();
        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);

        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $this->clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // Native Event is also dispatched
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // FakeLockProvider had lock acquisition called (lock -> released)
        // Locks are released so locks collection is empty
        // FakeCacheStore has markExecuted records
        $cacheData = $this->cache->getData();
        $this->assertNotEmpty($cacheData);

        // Verify the key written by markExecuted exists
        $event = $schedule->events()[0];
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertArrayHasKey($key, $cacheData);

        // Value is the dueAt timestamp
        $expectedTimestamp = (new DateTimeImmutable('2024-01-15 12:00:00'))->getTimestamp();
        $this->assertSame($expectedTimestamp, $cacheData[$key]);
    }
}
