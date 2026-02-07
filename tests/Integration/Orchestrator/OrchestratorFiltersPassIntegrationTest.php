<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;

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

        $this->container->instance(EventMutex::class, $this->eventMutex);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);

        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->innerDispatcher = new FakeDispatcher($defaultResult);
        $this->app = new FakeApplication();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
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
     * @testdox TI.5 Real Schedule with everyMinute + when(false) → not dispatched
     */
    public function testRealScheduleWithFiltersPass(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2024-01-15 12:00:00'));

        $tracker = $this->createTracker();

        $schedule = new ClockAwareSchedule($clock);
        $schedule->exec('echo filtered')
            ->everyMinute()
            ->when(function () {
                return false;
            });

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // when(false) のため filtersPass で弾かれ、dispatch されない
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.6 withoutOverlapping + mutex locked → skipped via filtersPass
     */
    public function testWithoutOverlappingBlocksViaMutex(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2024-01-15 12:00:00'));

        $tracker = $this->createTracker();

        $schedule = new ClockAwareSchedule($clock);
        $event = $schedule->exec('echo overlapping')
            ->everyMinute()
            ->withoutOverlapping();

        // mutex をロック済みにする（前回実行がまだ動いている状態をシミュレート）
        $this->eventMutex->create($event);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // withoutOverlapping + mutex locked → filtersPass でスキップ
        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox TI.7 Mixed constraints: 3 events (when(true), when(false), skip(true)) → only 1 dispatched
     */
    public function testMixedConstraintsThroughPipeline(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2024-01-15 12:00:00'));

        $tracker = $this->createTracker();

        $schedule = new ClockAwareSchedule($clock);

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

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = new DefaultScheduleOrchestrator(
            $trackingDispatcher,
            $clock,
            $tracker,
            $this->logger,
            new NullSleeper()
        );

        $orchestrator->run($schedule, $this->app, $this->createShouldContinue(1));

        // 3 イベント中、1 つだけが filtersPass を通過
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // dispatch されたのは 'echo pass' のイベント
        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertStringContainsString('echo pass', (string) $dispatched[0]['event']->command);
    }
}
