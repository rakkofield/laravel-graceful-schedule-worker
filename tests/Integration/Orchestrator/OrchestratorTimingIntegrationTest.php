<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\AdvancingClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * 分 boundary でのディスパッチ制御の統合テスト
 */
class OrchestratorTimingIntegrationTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $eventMutex;

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

        $this->schedule = new SpySchedule($this->eventMutex, $schedulingMutex);
        $this->app = new FakeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
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
     * @testdox T14.1 Event not dispatched twice in same minute
     */
    public function testEventNotDispatchedTwiceInSameMinute(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = new FakeExecutionTracker();

        $event = new ClockAwareEvent($this->eventMutex, 'echo timing', $clock);
        $event->cron('0 * * * *');
        $this->schedule->setDueEvents([$event]);

        $dispatcher = new FakeDispatcher(FakeStartedDispatchResult::create('t', 'echo timing', 'fake'));

        $orchestrator = new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, new NullLogger(), 0);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(3));

        $this->assertSame(1, $dispatcher->getDispatchCount());
    }

    /**
     * @testdox T14.2 Event not dispatched when second is not zero
     */
    public function testEventNotDispatchedWhenSecondIsNotZero(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:30'));
        $tracker = new FakeExecutionTracker();

        $event = new ClockAwareEvent($this->eventMutex, 'echo timing30', $clock);
        $event->cron('0 * * * *');
        $this->schedule->setDueEvents([$event]);

        $dispatcher = new FakeDispatcher(FakeStartedDispatchResult::create('t', 'echo timing30', 'fake'));

        $orchestrator = new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, new NullLogger(), 0);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(3));

        $this->assertSame(0, $dispatcher->getDispatchCount());
    }

    /**
     * @testdox T14.3 Dispatch happens only at second zero boundary
     */
    public function testDispatchHappensOnlyAtSecondZeroBoundary(): void
    {
        // 秒=58 から開始、1秒ずつ進む
        // now() 呼び出し: 初期化で2回(L76,L79) + ループ5回(L87) = 7回
        // 時刻: 58, 59, 0, 1, 2, 3, 4
        // ループで見える時刻: 0, 1, 2, 3, 4
        // 秒=0 で dispatch → 1回のみ
        $clock = new AdvancingClock(new DateTimeImmutable('2024-01-15 12:00:58'), 1);
        $tracker = new FakeExecutionTracker();

        $event = new ClockAwareEvent($this->eventMutex, 'echo boundary', $clock);
        $event->cron('0 * * * *');
        $this->schedule->setDueEvents([$event]);

        $dispatcher = new FakeDispatcher(FakeStartedDispatchResult::create('t', 'echo boundary', 'fake'));

        $orchestrator = new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, new NullLogger(), 0);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(5));

        $this->assertSame(1, $dispatcher->getDispatchCount());
    }
}
