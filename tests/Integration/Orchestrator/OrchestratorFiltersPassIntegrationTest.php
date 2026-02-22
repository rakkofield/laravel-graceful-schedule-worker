<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\AdvancingClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeLockProvider;

/**
 * Orchestrator filtersPass integration test
 *
 * Uses a real ClockAwareSchedule (not SpySchedule) to verify that
 * filtersPass works correctly in the Orchestrator -> TrackingDispatcher -> FakeDispatcher pipeline.
 *
 * Composition: Orchestrator -> TrackingDispatcher -> FakeDispatcher
 *              + CacheExecutionTracker + FakeCacheStore + FakeLockProvider
 */
class OrchestratorFiltersPassIntegrationTest extends TestCase
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
        $trackingDispatcher = new TrackingDispatcher(
            $this->innerDispatcher,
            $tracker,
            $this->logger,
            $this->clock,
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
     * @testdox TI.5 Real Schedule with everyMinute + when(false) → not dispatched
     */
    public function testRealScheduleWithFiltersPass(): void
    {
        $schedule = new ClockAwareSchedule($this->clock, 'local', null, new TimezoneResolver());
        $schedule->exec('echo filtered')
            ->everyMinute()
            ->when(function () {
                return false;
            });

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // Rejected by filtersPass due to when(false), not dispatched
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.6 withoutOverlapping + mutex locked → skipped via filtersPass
     */
    public function testWithoutOverlappingBlocksViaMutex(): void
    {
        $schedule = new ClockAwareSchedule($this->clock, 'local', null, new TimezoneResolver());
        $event = $schedule->exec('echo overlapping')
            ->everyMinute()
            ->withoutOverlapping();

        // Pre-lock the mutex (simulate a previous execution still running)
        $this->eventMutex->create($event);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // withoutOverlapping + mutex locked -> skipped by filtersPass
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.7 Mixed constraints: 3 events (when(true), when(false), skip(true)) → only 1 dispatched
     */
    public function testMixedConstraintsThroughPipeline(): void
    {
        $schedule = new ClockAwareSchedule($this->clock, 'local', null, new TimezoneResolver());

        $schedule->exec('echo pass')
            ->everyMinute()
            ->when(function () {
                return true;
            });

        $schedule->exec('echo fail-when')
            ->everyMinute()
            ->when(function () {
                return false;
            });

        $schedule->exec('echo fail-skip')
            ->everyMinute()
            ->skip(function () {
                return true;
            });

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // Only 1 out of 3 events passes filtersPass
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // The dispatched event is the 'echo pass' one
        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertStringContainsString('echo pass', (string) $dispatched[0]['event']->command);
    }

    /**
     * @testdox TI.8 environments filter through pipeline → not dispatched
     */
    public function testEnvironmentsFilterThroughPipeline(): void
    {
        $schedule = new ClockAwareSchedule($this->clock, 'local', null, new TimezoneResolver());
        $schedule->exec('echo env-test')
            ->everyMinute()
            ->environments(['production']);

        $this->app->setEnvironment('testing');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // environments(['production']) + app env=testing -> isDue = false -> not dispatched
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.9 maintenance mode filter through pipeline → only evenInMaintenanceMode dispatched
     */
    public function testMaintenanceModeFilterThroughPipeline(): void
    {
        $this->app->setIsDownForMaintenance(true);

        $schedule = new ClockAwareSchedule($this->clock, 'local', null, new TimezoneResolver());

        $schedule->exec('echo maintenance-ok')
            ->everyMinute()
            ->evenInMaintenanceMode();

        $schedule->exec('echo maintenance-blocked')
            ->everyMinute();

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // Only the event with evenInMaintenanceMode() is dispatched
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());
        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertStringContainsString('echo maintenance-ok', (string) $dispatched[0]['event']->command);
    }

    /**
     * @testdox TI.10 Evaluation time consistency: dueEvents and filtersPass use the same frozen time
     */
    public function testEvaluationTimeConsistency(): void
    {
        // AdvancingClock: advances 1 second on each now() call
        // Starts at 12:00:00. Without freeze, dueEvents and between could evaluate at different times.
        $advancingClock = new AdvancingClock(
            new DateTimeImmutable('2024-01-15 12:00:00'),
            1
        );

        $schedule = new ClockAwareSchedule($advancingClock, 'local', null, new TimezoneResolver());

        // between('11:59', '12:01') -> 12:00:00 is within range
        // Without freeze, AdvancingClock could advance during between evaluation
        $capturedTimes = [];
        $schedule->exec('echo consistency-test')
            ->everyMinute()
            ->when(function () use ($schedule, &$capturedTimes) {
                // Record the event clock's now() inside the when() filter
                $events = $schedule->events();
                $event = $events[0];
                $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
                $reflection->setAccessible(true);
                $eventClock = $reflection->getValue($event);
                $capturedTimes[] = $eventClock->now();
                return true;
            })
            ->between('11:59', '12:01');

        // Configure Orchestrator
        // Orchestrator's own clock also starts at 12:00:00 (FixedClock)
        $orchestratorClock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = $this->createTracker();
        $trackingDispatcher = new TrackingDispatcher(
            $this->innerDispatcher,
            $tracker,
            $this->logger,
            $orchestratorClock,
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

        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $orchestratorClock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // evaluateAt freezes the clock, so both when() and between()
        // are evaluated at the same time (12:00:00)
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // All captured times should be the same value
        $this->assertNotEmpty($capturedTimes);
        foreach ($capturedTimes as $time) {
            $this->assertEquals($capturedTimes[0], $time);
        }
    }
}
