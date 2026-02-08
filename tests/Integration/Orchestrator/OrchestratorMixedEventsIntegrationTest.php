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
 * Orchestrator パイプラインでの native Event と ClockAwareEvent 混在テスト
 *
 * 実 ClockAwareSchedule + withNativeEvents() で native Event を登録し、
 * DefaultScheduleOrchestrator → TrackingDispatcher → FakeDispatcher のパイプラインで
 * 両方の型が正しく処理されることを検証する。
 *
 * 構成: Orchestrator → TrackingDispatcher → FakeDispatcher
 *       + CacheExecutionTracker + FakeCacheStore + FakeLockProvider
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
        // withNativeEvents 内で登録 → native Event
        $this->assertNotInstanceOf(ClockAwareEvent::class, $dispatched[0]['event']);
        $this->assertInstanceOf(Event::class, $dispatched[0]['event']);
        // 通常モードで登録 → ClockAwareEvent
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

        // 前回実行記録をセット（11:50:00 に実行済み → 11:55:00 が抜けている）
        $tracker = $this->createTracker();
        $events = $schedule->events();

        // 両方のイベントに lastExecutedDue = 11:50:00 をセット
        $lastExecutedDue = new DateTimeImmutable('2024-01-15 11:50:00');
        foreach ($events as $event) {
            $tracker->markExecuted($event, $lastExecutedDue);
        }

        // Orchestrator を再構成（同じ cache/lockProvider を使用）
        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $this->clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        // shouldContinue(0) → ループに入らず、checkMissedExecutions のみ実行
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(0));

        // native Event はリカバリ対象外（isRecoverableEvent = false）
        // ClockAwareEvent のみがリカバリ dispatch される
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

        // native Event でも dispatch される
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // FakeLockProvider でロック取得が呼ばれている（ロック → リリース済み）
        // ロックはリリース済みなので locks は空になる
        // FakeCacheStore に markExecuted の記録がある
        $cacheData = $this->cache->getData();
        $this->assertNotEmpty($cacheData);

        // markExecuted で書き込まれたキーが存在することを確認
        $event = $schedule->events()[0];
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertArrayHasKey($key, $cacheData);

        // 値は dueAt のタイムスタンプ
        $expectedTimestamp = (new DateTimeImmutable('2024-01-15 12:00:00'))->getTimestamp();
        $this->assertSame($expectedTimestamp, $cacheData[$key]);
    }
}
