<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;

/**
 * Orchestrator → TrackingDispatcher → CompositeDispatcher の統合テスト
 */
class OrchestratorCompositeIntegrationTest extends TestCase
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
    private $localDispatcher;

    /** @var FakeDispatcher */
    private $sfnDispatcher;

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

        $localResult = FakeStartedDispatchResult::create('local-id', 'echo local', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'echo sfn', 'stepfunctions');
        $this->localDispatcher = new FakeDispatcher($localResult);
        $this->sfnDispatcher = new FakeDispatcher($sfnResult);

        $this->schedule = new SpySchedule($this->eventMutex, $schedulingMutex);
        $this->app = new FakeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param FixedClock $clock
     * @return DefaultScheduleOrchestrator
     */
    private function createOrchestrator(FixedClock $clock): DefaultScheduleOrchestrator
    {
        $composite = new CompositeDispatcher(
            ['local' => $this->localDispatcher, 'stepfunctions' => $this->sfnDispatcher],
            'local',
            $this->logger
        );
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $trackingDispatcher = new TrackingDispatcher($composite, $tracker, $this->logger);

        return new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);
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
     * @testdox T10.1 Events routed to correct dispatcher by type
     */
    public function testEventsRoutedToCorrectDispatcherByType(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));

        $localEvent = new ClockAwareEvent($this->eventMutex, 'echo local', $clock);
        $localEvent->cron('0 * * * *');
        $localEvent->dispatchVia('local');

        $sfnEvent = new ClockAwareEvent($this->eventMutex, 'echo sfn', $clock);
        $sfnEvent->cron('0 * * * *');
        $sfnEvent->dispatchVia('stepfunctions');

        $this->schedule->setDueEvents([$localEvent, $sfnEvent]);

        $orchestrator = $this->createOrchestrator($clock);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        $this->assertSame(1, $this->localDispatcher->getDispatchCount());
        $this->assertSame(1, $this->sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox T10.2 Default dispatcher type used when not specified
     */
    public function testDefaultDispatcherTypeUsedWhenNotSpecified(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));

        $event = new ClockAwareEvent($this->eventMutex, 'echo default', $clock);
        $event->cron('0 * * * *');
        // dispatchVia() を呼ばない

        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator($clock);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        $this->assertSame(1, $this->localDispatcher->getDispatchCount());
        $this->assertSame(0, $this->sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox T10.3 Mixed dispatcher types with tracking
     */
    public function testMixedDispatcherTypesWithTracking(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));

        $localEvent = new ClockAwareEvent($this->eventMutex, 'echo local-tracked', $clock);
        $localEvent->cron('0 * * * *');
        $localEvent->dispatchVia('local');

        $sfnEvent = new ClockAwareEvent($this->eventMutex, 'echo sfn-tracked', $clock);
        $sfnEvent->cron('0 * * * *');
        $sfnEvent->dispatchVia('stepfunctions');

        $this->schedule->setDueEvents([$localEvent, $sfnEvent]);

        $orchestrator = $this->createOrchestrator($clock);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        // 両方とも markExecuted される
        $key1 = 'schedule:tracker:last:' . $localEvent->mutexName();
        $key2 = 'schedule:tracker:last:' . $sfnEvent->mutexName();
        $this->assertTrue($this->cache->has($key1));
        $this->assertTrue($this->cache->has($key2));
    }

    /**
     * @testdox T10.4 Cleanup and stopAll delegate to all children
     */
    public function testCleanupAndStopAllDelegateToAllChildren(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));

        $event = new ClockAwareEvent($this->eventMutex, 'echo cleanup', $clock);
        $event->cron('0 * * * *');

        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator($clock);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        // cleanup: ループ中に呼ばれる
        $this->assertGreaterThanOrEqual(1, $this->localDispatcher->getCleanupCallCount());
        $this->assertGreaterThanOrEqual(1, $this->sfnDispatcher->getCleanupCallCount());

        // stopAll: 終了時に呼ばれる
        $this->assertSame(1, $this->localDispatcher->getStopAllCallCount());
        $this->assertSame(1, $this->sfnDispatcher->getStopAllCallCount());
    }
}
