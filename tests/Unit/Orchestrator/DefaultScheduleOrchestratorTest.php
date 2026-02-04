<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\StubProcess;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

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
        $this->app = new FakeApplication();
        // 時刻を 12:00:00 に固定（秒が 0 の状態）
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $this->logger = new NullLogger();
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
     * @testdox T3.1 run_executes_due_events
     */
    public function testRunExecutesDueEvents(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
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

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
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

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
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

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
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

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
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

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
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

    /**
     * @testdox dispatch_failure_is_handled
     */
    public function testDispatchFailureIsHandled(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        // 失敗した結果を返すように設定
        $result = FakeDispatchResult::failed($event->mutexName(), 'echo test', 'Connection refused', 'fake');
        $this->dispatcher->setResult($result);

        // ログをキャプチャするために SpyLogger を使用
        $logMessages = [];
        $spyLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $spyLogger->method('error')->willReturnCallback(function ($message, $context) use (&$logMessages) {
            $logMessages[] = ['message' => $message, 'context' => $context];
        });

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $spyLogger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // ディスパッチが呼ばれたことを確認
        $this->assertSame(1, $this->dispatcher->getDispatchCount());

        // エラーログにメッセージが出力されていることを確認
        $this->assertNotEmpty($logMessages);
        $this->assertStringContainsString('Failed to dispatch event', $logMessages[0]['message']);
    }

    /**
     * @testdox stopRunningProcesses stops all running processes
     */
    public function testStopRunningProcessesStopsAllProcesses(): void
    {
        $event = $this->createEvent('sleep 100');
        $this->schedule->setDueEvents([$event]);

        // StubProcess を使用して LocalDispatchResult を作成
        $stubProcess = new StubProcess(true);
        $localResult = LocalDispatchResult::success($stubProcess, $event->mutexName(), 'sleep 100');

        $this->dispatcher->setResult($localResult);

        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // run() 終了後、プロセスが停止されていることを確認
        $this->assertTrue($stubProcess->wasStopped());
        $this->assertFalse($stubProcess->isRunning());
    }

    /**
     * @testdox T3.6 同一分内で複数回ループしても1回しかディスパッチされない
     */
    public function testOnlyDispatchesOncePerMinute(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // 時刻を毎分0秒に固定（setUp で 12:00:00 に設定済み）
        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        // shouldContinue で 3 回ループを回す
        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 3;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // 3 回ループしても、同一分内なので 1 回しかディスパッチされない
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T3.7 秒が0でない場合はディスパッチをスキップする
     */
    public function testSkipsDispatchWhenSecondIsNotZero(): void
    {
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        // 秒を 30 に設定（0 でないのでスキップされる）
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:30'));
        $tracker = new NullExecutionTracker();
        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        // shouldContinue で 2 回ループを回す
        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 2;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // 秒が 0 でないため、ディスパッチは呼ばれない
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
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
     * @testdox T3.20 Acquires lock before dispatch when tracker is set
     */
    public function testAcquiresLockBeforeDispatch(): void
    {
        $tracker = new FakeExecutionTracker();
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // ロックが取得されていることを確認
        $locks = $tracker->getLocks();
        $this->assertNotEmpty($locks);
    }

    /**
     * @testdox T3.21 Skips event when lock not acquired
     */
    public function testSkipsEventWhenLockNotAcquired(): void
    {
        $tracker = new FakeExecutionTracker();
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        // ロック取得を失敗させる
        $dueAt = Carbon::parse('2024-01-15 12:00:00');
        $tracker->setLockResult($event->mutexName(), $dueAt, false);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // ディスパッチがスキップされることを確認
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T3.22 Marks executed after successful dispatch
     */
    public function testMarksExecutedAfterSuccessfulDispatch(): void
    {
        $tracker = new FakeExecutionTracker();
        $event = $this->createEvent('echo test');
        $this->schedule->setDueEvents([$event]);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // 実行が記録されていることを確認
        $executed = $tracker->getExecuted();
        $this->assertArrayHasKey($event->mutexName(), $executed);
    }

    /**
     * @testdox T3.23 Checks missed executions at startup and recovers
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
        $missedDue = Carbon::parse('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // リカバリでディスパッチされることを確認
        $this->assertSame(1, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T3.24 Skips missed event when getMissedDueIfRecoverable returns null
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

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // 取りこぼしなしのためディスパッチされないことを確認
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T3.25 Does not recover non-recoverable event
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
        $missedDue = Carbon::parse('2024-01-15 11:00:00');
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        $result = FakeDispatchResult::success($event->mutexName(), 'echo test', 'fake');
        $this->dispatcher->setResult($result);

        $orchestrator = new DefaultScheduleOrchestrator($this->dispatcher, $this->clock, $tracker, $this->logger);
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // recoverable でないためディスパッチされないことを確認
        $this->assertSame(0, $this->dispatcher->getDispatchCount());
    }

    /**
     * @testdox T3.26 Recovery dispatch failure is logged and markExecuted is not called
     */
    public function testRecoveryDispatchFailureIsHandled(): void
    {
        $tracker = new FakeExecutionTracker();

        // リカバリ対象のイベントを設定
        $event = $this->createClockAwareEvent('echo test');
        $event->cron('0 * * * *');
        $event->enableRecovery();

        $missedDue = Carbon::parse('2024-01-15 11:00:00');

        // FakeExecutionTracker でリカバリ対象を設定
        $tracker->setRecoverableResult($event->mutexName(), $missedDue);

        // ディスパッチ失敗を設定
        $failedResult = FakeDispatchResult::failed($event->mutexName(), 'echo test', 'Dispatch failed', 'fake');
        $this->dispatcher->setResult($failedResult);

        // スケジュールにイベントを追加（due ではない）
        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        // ログキャプチャ
        $logMessages = [];
        $spyLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $spyLogger->method('error')->willReturnCallback(function ($message, $context) use (&$logMessages) {
            $logMessages[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        });
        $spyLogger->method('info')->willReturnCallback(function ($message, $context) use (&$logMessages) {
            $logMessages[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });

        $orchestrator = new DefaultScheduleOrchestrator(
            $this->dispatcher,
            $this->clock,
            $tracker,
            $spyLogger
        );
        $orchestrator->setSleepMicroseconds(0);

        $callCount = 0;
        $shouldContinue = function () use (&$callCount) {
            $callCount++;
            return $callCount <= 1;
        };

        $orchestrator->run($this->schedule, $this->app, $shouldContinue);

        // エラーログが出力されていることを確認
        $errorLogs = array_filter($logMessages, function ($log) {
            return $log['level'] === 'error';
        });
        $this->assertNotEmpty($errorLogs);
        $this->assertStringContainsString('Failed to dispatch', array_values($errorLogs)[0]['message']);

        // markExecuted が呼ばれていないことを確認
        $this->assertEmpty($tracker->getExecuted());
    }
}
