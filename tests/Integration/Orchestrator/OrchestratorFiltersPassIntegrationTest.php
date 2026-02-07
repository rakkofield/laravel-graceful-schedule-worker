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
 * Orchestrator の filtersPass 統合テスト
 *
 * 実 ClockAwareSchedule を使用し（SpySchedule ではなく）、
 * filtersPass が Orchestrator → TrackingDispatcher → FakeDispatcher のパイプラインで
 * 正しく機能することを検証する。
 *
 * 構成: Orchestrator → TrackingDispatcher → FakeDispatcher
 *       + CacheExecutionTracker + FakeCacheStore + FakeLockProvider
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
     * @testdox TI.5 Real Schedule with everyMinute + when(false) → not dispatched
     */
    public function testRealScheduleWithFiltersPass(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);
        $schedule->exec('echo filtered')
            ->everyMinute()
            ->when(function () {
                return false;
            });

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // when(false) のため filtersPass で弾かれ、dispatch されない
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.6 withoutOverlapping + mutex locked → skipped via filtersPass
     */
    public function testWithoutOverlappingBlocksViaMutex(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);
        $event = $schedule->exec('echo overlapping')
            ->everyMinute()
            ->withoutOverlapping();

        // mutex をロック済みにする（前回実行がまだ動いている状態をシミュレート）
        $this->eventMutex->create($event);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // withoutOverlapping + mutex locked → filtersPass でスキップ
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.7 Mixed constraints: 3 events (when(true), when(false), skip(true)) → only 1 dispatched
     */
    public function testMixedConstraintsThroughPipeline(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);

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

        // 3 イベント中、1 つだけが filtersPass を通過
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // dispatch されたのは 'echo pass' のイベント
        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertStringContainsString('echo pass', (string) $dispatched[0]['event']->command);
    }

    /**
     * @testdox TI.8 environments filter through pipeline → not dispatched
     */
    public function testEnvironmentsFilterThroughPipeline(): void
    {
        $schedule = new ClockAwareSchedule($this->clock);
        $schedule->exec('echo env-test')
            ->everyMinute()
            ->environments(['production']);

        $this->app->setEnvironment('testing');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // environments(['production']) + app env=testing → isDue = false → dispatch されない
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.9 maintenance mode filter through pipeline → only evenInMaintenanceMode dispatched
     */
    public function testMaintenanceModeFilterThroughPipeline(): void
    {
        $this->app->setIsDownForMaintenance(true);

        $schedule = new ClockAwareSchedule($this->clock);

        $schedule->exec('echo maintenance-ok')
            ->everyMinute()
            ->evenInMaintenanceMode();

        $schedule->exec('echo maintenance-blocked')
            ->everyMinute();

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // evenInMaintenanceMode() ありのイベントのみ dispatch される
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());
        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertStringContainsString('echo maintenance-ok', (string) $dispatched[0]['event']->command);
    }

    /**
     * @testdox TI.10 Evaluation time consistency: dueEvents and filtersPass use the same frozen time
     */
    public function testEvaluationTimeConsistency(): void
    {
        // AdvancingClock: now() を呼ぶたびに 1 秒進む
        // 12:00:00 からスタート。freeze なしなら dueEvents と between で異なる時刻になりうる。
        $advancingClock = new AdvancingClock(
            new DateTimeImmutable('2024-01-15 12:00:00'),
            1
        );

        $schedule = new ClockAwareSchedule($advancingClock);

        // between('11:59', '12:01') → 12:00:00 は範囲内
        // AdvancingClock で freeze なしなら between 評価時に clock が進んでしまう可能性がある
        $capturedTimes = [];
        $schedule->exec('echo consistency-test')
            ->everyMinute()
            ->when(function () use ($schedule, &$capturedTimes) {
                // when() filter 内で event の clock の now() を記録
                $events = $schedule->events();
                $event = $events[0];
                $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
                $reflection->setAccessible(true);
                $eventClock = $reflection->getValue($event);
                $capturedTimes[] = $eventClock->now();
                return true;
            })
            ->between('11:59', '12:01');

        // Orchestrator を構成
        // Orchestrator 自身の clock も 12:00:00 から始める（AdvancingClock）
        $orchestratorClock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = $this->createTracker();
        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);

        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $orchestratorClock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // evaluateAt により freeze されるので、
        // when() と between() の両方が同一時刻（12:00:00）で評価される
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // captured times はすべて同じ値であること
        $this->assertNotEmpty($capturedTimes);
        foreach ($capturedTimes as $time) {
            $this->assertEquals($capturedTimes[0], $time);
        }
    }
}
