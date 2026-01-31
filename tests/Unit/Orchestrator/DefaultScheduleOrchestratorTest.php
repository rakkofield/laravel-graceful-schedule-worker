<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpySchedule;

class DefaultScheduleOrchestratorTest extends TestCase
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

    /** @var Application */
    private $app;

    /** @var FixedClock */
    private $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->eventMutex = new FakeEventMutex();
        $this->schedulingMutex = new FakeSchedulingMutex();

        $defaultResult = FakeDispatchResult::success('test-id', 'echo test', 'fake');
        $this->dispatcher = new FakeDispatcher($defaultResult);
        $this->schedule = new SpySchedule($this->eventMutex, $this->schedulingMutex);
        $this->app = $this->createMockApplication();
        // 時刻を 12:00:00 に固定（秒が 0 の状態）
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @return Application
     */
    private function createMockApplication(): Application
    {
        // Application インターフェースのモックを作成
        /** @var Application $app */
        $app = $this->createMock(Application::class);
        return $app;
    }

    /**
     * @param string $command
     * @return Event
     */
    private function createEvent(string $command): Event
    {
        return new Event($this->eventMutex, $command);
    }

    /**
     * @testdox T3.1 run_executes_due_events
     */
    public function testRunExecutesDueEvents(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock);
        $orchestrator->setSleepMicroseconds(0); // テスト時はスリープを無効化

        // shouldContinue は 1 回だけ true を返してからすぐ false を返す
        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event, $dispatched[0]['event']);
    }

    /**
     * @testdox T3.2 run_skips_non_due_events
     */
    public function testRunSkipsNonDueEvents(): void
    {
        // due でないイベントは dueEvents に含まれないため、空配列を設定
        $this->schedule->setDueEvents([]);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T3.3 run_calls_dispatcher_dispatchEvent_for_each_event
     */
    public function testRunCallsDispatcherDispatchEventForEachEvent(): void
    {
        $event1 = $this->createEvent('echo test1');
        $event2 = $this->createEvent('echo test2');
        $event3 = $this->createEvent('echo test3');
        $this->schedule->setDueEvents([$event1, $event2, $event3]);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        $this->assertSame(3, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event1, $dispatched[0]['event']);
        $this->assertSame($event2, $dispatched[1]['event']);
        $this->assertSame($event3, $dispatched[2]['event']);
    }

    /**
     * @testdox T3.4 run_stops_when_shouldContinue_false
     */
    public function testRunStopsWhenShouldContinueFalse(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock);
        $orchestrator->setSleepMicroseconds(0);

        // 最初から false を返す
        $shouldContinue = function () {
            return false;
        };

        $result = $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // shouldContinue が false なら即座に終了し、イベントはディスパッチされない
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
        $this->assertTrue($result);
    }

    /**
     * @testdox run_returns_true_on_success
     */
    public function testRunReturnsTrueOnSuccess(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $result = $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        $this->assertTrue($result);
    }

    /**
     * @testdox run_manages_local_dispatch_results
     */
    public function testRunManagesLocalDispatchResults(): void
    {
        // LocalDispatchResult を返すようにセットアップ
        // 実際のプロセスは使わず、検証のためにモックを使用
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'local');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // 正常にディスパッチされたことを確認
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }
}
