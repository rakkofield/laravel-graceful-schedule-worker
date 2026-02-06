<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * ExecutionTracker の統合テスト
 *
 * Orchestrator と Tracker が連携して動作することを確認します。
 */
class ExecutionTrackerIntegrationTest extends TestCase
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

    /** @var FakeCacheStore */
    private $cache;

    /** @var FakeLockProvider */
    private $lockProvider;

    /** @var NullLogger */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->eventMutex = new FakeEventMutex();
        $this->schedulingMutex = new FakeSchedulingMutex();
        $this->lockProvider = new FakeLockProvider();
        $this->cache = new FakeCacheStore($this->lockProvider);
        $this->logger = new NullLogger();

        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->dispatcher = new FakeDispatcher($defaultResult);
        $this->schedule = new SpySchedule($this->eventMutex, $this->schedulingMutex);
        $this->app = new FakeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param string $command
     * @param FixedClock $clock
     * @return ClockAwareEvent
     */
    private function createEvent(string $command, FixedClock $clock): ClockAwareEvent
    {
        return new ClockAwareEvent($this->eventMutex, $command, $clock);
    }

    /**
     * @testdox T5.2 Recovery within grace period
     */
    public function testRecoveryWithinGracePeriod(): void
    {
        // 11:05 の時点でテスト開始（11:00 の取りこぼしを検出）
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // recoverable なイベントを作成（grace period 2時間）
        $event = $this->createEvent('echo test', $clock);
        $event->cron('0 * * * *'); // 毎時0分
        $event->withGracePeriod(120); // 2時間

        // 10:00 に実行記録を設定
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]); // due events は空
        $this->schedule->addEvent($event);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // FakeDispatcher を TrackingDispatcher でラップ
        $trackingDispatcher = new TrackingDispatcher($this->dispatcher, $tracker, $this->logger);

        // sleepMicroseconds = 0 でテスト時はスリープを無効化
        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // 11:00 のタスクがリカバリ実行される
        $this->assertSame(1, $this->dispatcher->getDispatchCount());

        // 実行記録が更新されていることを確認（キャッシュの値をチェック）
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $timestamp = $this->cache->get($key);
        $this->assertNotNull($timestamp);
        // リカバリ時刻（11:00）が記録されている
        $lastExecuted = Carbon::createFromTimestamp((int) $timestamp);
        $this->assertSame(11, $lastExecuted->hour);
    }

    /**
     * @testdox T5.3 No recovery after grace period
     */
    public function testNoRecoveryAfterGracePeriod(): void
    {
        // 14:05 の時点でテスト開始（grace period 2時間超過）
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 14:05:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // recoverable なイベントを作成（grace period 2時間）
        $event = $this->createEvent('echo test', $clock);
        $event->cron('0 * * * *'); // 毎時0分
        $event->withGracePeriod(120); // 2時間

        // 10:00 に実行記録を設定（14:00 では grace period 超過）
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // FakeDispatcher を TrackingDispatcher でラップ
        $trackingDispatcher = new TrackingDispatcher($this->dispatcher, $tracker, $this->logger);

        // sleepMicroseconds = 0 でテスト時はスリープを無効化
        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // grace period 超過のためリカバリされない
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T5.4 No duplicate execution with lock
     */
    public function testNoDuplicateExecutionWithLock(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // イベントを作成
        $event = $this->createEvent('echo test', $clock);
        $event->cron('0 * * * *');

        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // FakeDispatcher を TrackingDispatcher でラップ
        $trackingDispatcher = new TrackingDispatcher($this->dispatcher, $tracker, $this->logger);

        // 最初の Orchestrator がロックを取得して実行（sleepMicroseconds = 0）
        $orchestrator1 = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $callCount1 = 0;
        $shouldContinue1 = function () use (&$callCount1) {
            $callCount1++;
            return $callCount1 <= 1;
        };

        $orchestrator1->run($this->schedule, $this->app, $shouldContinue1);

        // 1回目は実行される
        $this->assertSame(1, $this->dispatcher->getDispatchCount());

        // 2つ目の Orchestrator は同じロックを取得できない（sleepMicroseconds = 0）
        $orchestrator2 = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $callCount2 = 0;
        $shouldContinue2 = function () use (&$callCount2) {
            $callCount2++;
            return $callCount2 <= 1;
        };

        $orchestrator2->run($this->schedule, $this->app, $shouldContinue2);

        // 2回目はロック取得できないのでスキップされる
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T5.5 Multiple events are tracked independently
     */
    public function testMultipleEventsAreTrackedIndependently(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // 2つのイベントを作成
        $event1 = $this->createEvent('echo test1', $clock);
        $event1->cron('0 * * * *');

        $event2 = $this->createEvent('echo test2', $clock);
        $event2->cron('*/5 * * * *');

        $this->schedule->setDueEvents([$event1, $event2]);

        $result = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // FakeDispatcher を TrackingDispatcher でラップ
        $trackingDispatcher = new TrackingDispatcher($this->dispatcher, $tracker, $this->logger);

        // sleepMicroseconds = 0 でテスト時はスリープを無効化
        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // 両方のイベントが実行される
        $this->assertSame(2, $this->dispatcher->getDispatchCount());

        // 両方の実行記録が存在することを確認（キャッシュの値をチェック）
        $key1 = 'schedule:tracker:last:' . $event1->mutexName();
        $key2 = 'schedule:tracker:last:' . $event2->mutexName();
        $this->assertNotNull($this->cache->get($key1));
        $this->assertNotNull($this->cache->get($key2));
    }

    /**
     * @testdox T5.5b Graceful shutdown waits for running tasks
     */
    public function testGracefulShutdownWaitsForRunning(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        $event = $this->createEvent('echo test', $clock);
        $event->cron('0 * * * *');

        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // FakeDispatcher を TrackingDispatcher でラップ
        $trackingDispatcher = new TrackingDispatcher($this->dispatcher, $tracker, $this->logger);

        $orchestrator = new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);

        // shouldContinue は即座に false を返す（シャットダウンシミュレーション）
        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return false;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // stopAll が呼ばれていることを確認
        $this->assertSame(1, $this->dispatcher->getStopAllCallCount());

        // cleanup もループ中に呼ばれていないことを確認（ループに入っていないため）
        // shouldContinue が false なのでループに入らない
        $this->assertSame(0, $this->dispatcher->getCleanupCallCount());
    }
}
