<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Carbon\Carbon;
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
 * Orchestrator + TrackingDispatcher + CacheExecutionTracker の構成 smoke test
 *
 * 実クラスを結合し、FakeCacheStore + FakeDispatcher を末端に使う。
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
        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // markExecuted の記録を確認
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

        // 10:00 に最終実行記録
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        // リカバリ dispatch が実行される
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // dispatch に渡された dueAt が 11:00（missed due）であることを確認
        $dispatched = $this->innerDispatcher->getDispatched();
        $dueAt = $dispatched[0]['dueAt'];
        $this->assertSame(11, (int) Carbon::instance($dueAt)->format('H'));
        $this->assertSame(0, (int) Carbon::instance($dueAt)->format('i'));

        // markExecuted がリカバリ時刻（11:00）で記録される
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $lastExecuted = Carbon::createFromTimestamp((int) $this->cache->get($key));
        $this->assertSame(11, $lastExecuted->hour);
        $this->assertSame(0, $lastExecuted->minute);
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
        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

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
        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $event = new ClockAwareEvent($this->eventMutex, 'echo shutdown', $clock);
        $event->cron('0 * * * *');
        $this->schedule->setDueEvents([$event]);

        // shouldContinue が即 false → ループに入らず stopAll
        $orchestrator->run($this->schedule, $this->app, function () {
            return false;
        });

        // stopAll が全 dispatcher に伝播
        $this->assertSame(1, $localDispatcher->getStopAllCallCount());
        $this->assertSame(1, $sfnDispatcher->getStopAllCallCount());
    }
}
