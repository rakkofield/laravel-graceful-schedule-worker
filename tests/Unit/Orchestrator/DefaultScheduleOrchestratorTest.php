<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

/**
 * DefaultScheduleOrchestrator のユニットテスト
 *
 * Note: ロック取得・markExecuted・失敗ハンドリングは TrackingDispatcher の責務
 *       TrackingDispatcherTest でテスト済み
 */
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

    /** @var FakeApplication */
    private $app;

    /** @var FixedClock */
    private $clock;

    /** @var NullLogger */
    private $logger;

    /** @var NullSleeper */
    private $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->eventMutex = new FakeEventMutex();
        $this->schedulingMutex = new FakeSchedulingMutex();

        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->dispatcher = new FakeDispatcher($defaultResult);
        $this->schedule = new SpySchedule($this->eventMutex, $this->schedulingMutex);
        $this->app = new FakeApplication();
        // 時刻を 12:00:00 に固定（秒が 0 の状態）
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $this->logger = new NullLogger();
        $this->sleeper = new NullSleeper();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
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
     * @param string $command
     * @return ClockAwareEvent
     */
    private function createClockAwareEvent(string $command): ClockAwareEvent
    {
        return new ClockAwareEvent($this->eventMutex, $command, $this->clock);
    }

    /**
     * @param ExecutionTrackerInterface|null $tracker
     * @param LoggerInterface|null $logger
     * @return DefaultScheduleOrchestrator
     */
    private function createOrchestrator(
        $tracker = null,
        $logger = null
    ): DefaultScheduleOrchestrator {
        return new DefaultScheduleOrchestrator(
            $this->dispatcher,
            $this->clock,
            $tracker ?? new NullExecutionTracker(),
            $logger ?? $this->logger,
            $this->sleeper
        );
    }

    /**
     * @testdox DO.1 run_executes_due_events
     */
    public function testRunExecutesDueEvents(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event, $dispatched[0]['event']);
    }

    /**
     * @testdox DO.2 run_skips_non_due_events
     */
    public function testRunSkipsNonDueEvents(): void
    {
        // due でないイベントは dueEvents に含まれないため、空配列を設定
        $this->schedule->setDueEvents([]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.3 run_calls_dispatcher_dispatchEvent_for_each_event
     */
    public function testRunCallsDispatcherDispatchEventForEachEvent(): void
    {
        $event1 = $this->createEvent('echo test1');
        $event2 = $this->createEvent('echo test2');
        $event3 = $this->createEvent('echo test3');
        $this->schedule->setDueEvents([$event1, $event2, $event3]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(3, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event1, $dispatched[0]['event']);
        $this->assertSame($event2, $dispatched[1]['event']);
        $this->assertSame($event3, $dispatched[2]['event']);
    }

    /**
     * @testdox DO.4 run_stops_when_shouldContinue_false
     */
    public function testRunStopsWhenShouldContinueFalse(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();

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
     * @testdox DO.5
     */
    public function testRunReturnsTrueOnSuccess(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertTrue($result);
    }

    /**
     * @testdox DO.6
     */
    public function testRunManagesLocalDispatchResults(): void
    {
        // LocalDispatchResult を返すようにセットアップ
        // 実際のプロセスは使わず、検証のためにモックを使用
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'local');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // 正常にディスパッチされたことを確認
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.7
     */
    public function testStopAllIsCalledOnDispatcherWhenOrchestratorStops(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // run() 終了後、dispatcher の stopAll() が呼ばれることを確認
        $this->assertSame(1, $this->dispatcher->getStopAllCallCount());
    }

    /**
     * @testdox DO.8
     */
    public function testCleanupIsCalledOnDispatcherInEachLoopIteration(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(3));

        // 各ループで cleanup() が呼ばれることを確認（3回）
        $this->assertSame(3, $this->dispatcher->getCleanupCallCount());
    }

    /**
     * @testdox DO.9 同一分内で複数回ループしても1回しかディスパッチされない
     */
    public function testOnlyDispatchesOncePerMinute(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // 時刻を毎分0秒に固定（setUp で 12:00:00 に設定済み）
        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(3));

        // 3 回ループしても、同一分内なので 1 回しかディスパッチされない
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.10 秒が0でない場合はディスパッチをスキップする
     */
    public function testSkipsDispatchWhenSecondIsNotZero(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // 秒を 30 に設定（0 でないのでスキップされる）
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:30'));
        $orchestrator = new DefaultScheduleOrchestrator(
            $this->dispatcher,
            $clock,
            new NullExecutionTracker(),
            $this->logger,
            $this->sleeper
        );

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        // 秒が 0 でないため、ディスパッチは呼ばれない
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.11 dispatchEvent に dueAt が渡される
     */
    public function testPassesDueAtToDispatcher(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $dispatched = $this->dispatcher->getDispatched();
        $this->assertCount(1, $dispatched);

        // dueAt が渡されていることを確認
        $dueAt = $dispatched[0]['dueAt'];
        $this->assertInstanceOf(\DateTimeInterface::class, $dueAt);
        // 時刻の分が一致していることを確認（秒は0に正規化）
        $this->assertSame('2024-01-15 12:00:00', $dueAt->format('Y-m-d H:i:s'));
    }

    /**
     * @testdox DO.12 Checks missed executions at startup and recovers
     */
    public function testChecksMissedExecutionsAtStartupAndRecovers(): void
    {
        $tracker = new FakeExecutionTracker();

        // due ではないが recoverable なイベントを作成
        $event = $this->createClockAwareEvent('echo test');
        $event->cron('0 * * * *'); // 毎時0分
        $event->enableRecovery();  // リカバリを有効化

        // due events は空（通常のディスパッチは行われない）
        $this->schedule->setDueEvents([]);
        // schedule.events() には含まれる
        $this->schedule->addEvent($event);

        // 取りこぼしを設定: missedDue を返す
        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // リカバリでディスパッチされることを確認
        $this->assertSame(1, $this->dispatcher->getDispatchCount());

        // dueAt が missedDue であることを確認
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($missedDue->getTimestamp(), $dispatched[0]['dueAt']->getTimestamp());
    }

    /**
     * @testdox DO.13 Skips missed event when getMissedDueIfRecoverable returns null
     */
    public function testSkipsMissedEventWhenGetMissedDueIfRecoverableReturnsNull(): void
    {
        $tracker = new FakeExecutionTracker();

        // recoverable なイベントを作成
        $event = $this->createClockAwareEvent('echo test');
        $event->cron('0 * * * *'); // 毎時0分
        $event->enableRecovery();

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        // 取りこぼしなし（null を返す）
        $tracker->setRecoverableResult($event->mutexName(), null);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // 取りこぼしなしのためディスパッチされないことを確認
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.14 Does not recover non-recoverable event
     */
    public function testDoesNotRecoverNonRecoverableEvent(): void
    {
        $tracker = new FakeExecutionTracker();

        // recoverable でないイベント
        $event = $this->createClockAwareEvent('echo test');
        $event->cron('0 * * * *'); // 毎時0分
        // enableRecovery() を呼ばない

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        // 取りこぼしを設定（ただし recoverable でないのでチェックされない）
        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // recoverable でないためディスパッチされないことを確認
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.15 Logs info when recovering missed event
     */
    public function testLogsInfoWhenRecoveringMissedEvent(): void
    {
        $tracker = new FakeExecutionTracker();

        // リカバリ対象のイベントを設定
        $event = $this->createClockAwareEvent('echo test');
        $event->cron('0 * * * *');
        $event->enableRecovery();

        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $spyLogger = new SpyLogger();
        $orchestrator = $this->createOrchestrator($tracker, $spyLogger);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // info ログが出力されていることを確認
        $infoLogs = $spyLogger->getLogsByLevel('info');
        $this->assertNotEmpty($infoLogs);
        $this->assertStringContainsString('Recovering missed event', $infoLogs[0]['message']);
    }

    /**
     * @param int $maxCalls
     * @return callable
     */
    private function createShouldContinue(int $maxCalls = 1): callable
    {
        $callCount = 0;
        return function () use (&$callCount, $maxCalls) {
            return $callCount++ < $maxCalls;
        };
    }

    /**
     * @testdox DO.16 filtersPass when(true) → event is dispatched
     */
    public function testFiltersPassWhenTrueEventIsDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->when(function () {
            return true;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.17 filtersPass when(false) → event is not dispatched
     */
    public function testFiltersPassWhenFalseEventIsNotDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->when(function () {
            return false;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.18 filtersPass skip(true) → event is not dispatched
     */
    public function testFiltersPassSkipTrueEventIsNotDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->skip(function () {
            return true;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.19 filtersPass skip(false) → event is dispatched
     */
    public function testFiltersPassSkipFalseEventIsDispatched(): void
    {
        $event = $this->createEvent('echo test');
        $event->skip(function () {
            return false;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.20 filtersPass only dispatches events that pass filters
     */
    public function testFiltersPassOnlyDispatchesPassingEvents(): void
    {
        $event1 = $this->createEvent('echo pass');
        $event1->when(function () {
            return true;
        });

        $event2 = $this->createEvent('echo fail');
        $event2->when(function () {
            return false;
        });

        $event3 = $this->createEvent('echo skip');
        $event3->skip(function () {
            return true;
        });

        $this->schedule->setDueEvents([$event1, $event2, $event3]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(1, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($event1, $dispatched[0]['event']);
    }

    /**
     * @testdox DO.21 Recovery does not check filtersPass
     */
    public function testRecoveryDoesNotCheckFiltersPass(): void
    {
        $tracker = new FakeExecutionTracker();

        $event = $this->createClockAwareEvent('echo test');
        $event->cron('0 * * * *');
        $event->enableRecovery();
        // when(false) を設定 → filtersPass は false を返す
        $event->when(function () {
            return false;
        });

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $missedDue = new DateTimeImmutable('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = $this->createOrchestrator($tracker);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // リカバリでは filtersPass を呼ばないためディスパッチされる
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.22 filtersPass exception skips event and continues
     */
    public function testFiltersPassExceptionSkipsEventAndContinues(): void
    {
        $eventThatThrows = $this->createEvent('echo throw');
        $eventThatThrows->when(function () {
            throw new \RuntimeException('filter error');
        });

        $eventThatPasses = $this->createEvent('echo pass');
        $eventThatPasses->when(function () {
            return true;
        });

        $this->schedule->setDueEvents([$eventThatThrows, $eventThatPasses]);

        $spyLogger = new SpyLogger();
        $orchestrator = $this->createOrchestrator(null, $spyLogger);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // 例外イベントはスキップされ、正常イベントのみディスパッチされる
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
        $dispatched = $this->dispatcher->getDispatched();
        $this->assertSame($eventThatPasses, $dispatched[0]['event']);

        // warning ログが出力されることを検証
        $this->assertTrue($spyLogger->hasLogContaining('warning', 'filtersPass threw exception'));
    }

    /**
     * @testdox DO.23 filtersPass with Closure condition that returns false → not dispatched
     */
    public function testFiltersPassWithClosureCondition(): void
    {
        $event = $this->createClockAwareEvent('echo test');
        $event->everyMinute();
        $event->when(function () {
            return false;
        });
        $this->schedule->setDueEvents([$event]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox DO.24 filtersPass exception logs warning with details
     */
    public function testFiltersPassExceptionLogsWarning(): void
    {
        $event = $this->createEvent('echo throw');
        $event->when(function () {
            throw new \RuntimeException('custom filter error');
        });

        $this->schedule->setDueEvents([$event]);

        $spyLogger = new SpyLogger();
        $orchestrator = $this->createOrchestrator(null, $spyLogger);
        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue());

        // イベントはスキップされる
        $this->assertSame(0, $this->dispatcher->getDispatchCount());

        // warning ログの内容を検証
        $warningLogs = $spyLogger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('filtersPass threw exception', $warningLogs[0]['message']);
        $this->assertSame('custom filter error', $warningLogs[0]['context']['error']);
        $this->assertInstanceOf(\RuntimeException::class, $warningLogs[0]['context']['exception']);
    }
}
