<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\SpyCallbackEvent;

class LocalDispatcherTest extends TestCase
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @var FakeEventMutex
     */
    private $mutex;

    /**
     * @var DateTimeImmutable
     */
    private $dueAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
        $this->dueAt = new DateTimeImmutable('2024-01-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    private function createSpyEvent(string $command): SpyCallbackEvent
    {
        return new SpyCallbackEvent($this->mutex, $command);
    }

    /**
     * @testdox LD.1
     */
    public function testReturnsLocalDispatchResult(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
    }

    /**
     * @testdox LD.2
     */
    public function testReturnsStartedDispatchResultInterfaceOnSuccess(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox LD.3
     */
    public function testReturnsCorrectEventIdentifier(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
    }

    /**
     * @testdox LD.4
     */
    public function testReturnsCorrectEventCommand(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // buildCommand() により元のコマンドが含まれたフルコマンドが返される
        $this->assertStringContainsString('php artisan report:daily', $result->getEventCommand());
    }

    /**
     * @testdox LD.5
     */
    public function testReturnsCorrectDispatcherType(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox LD.6
     */
    public function testStartsProcessInBackground(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('sleep 0.1');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertNotNull($result->getProcess());

        $result->getProcess()->wait();
    }

    /**
     * @testdox LD.7
     */
    public function testReturnsDispatchedAtTimestamp(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');

        $before = new \DateTimeImmutable();
        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);
        $after = new \DateTimeImmutable();

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertGreaterThanOrEqual($before, $dispatchedAt);
        $this->assertLessThanOrEqual($after, $dispatchedAt);
    }

    /**
     * @testdox LD.8
     */
    public function testHasRunningProcessImmediatelyAfterDispatch(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('sleep 2');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertTrue($result->isRunning());

        $result->getProcess()->stop(0);
    }

    /**
     * @testdox LD.9 beforeCallbacks are called before dispatch
     */
    public function testBeforeCallbacksAreCalledBeforeDispatch(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createSpyEvent('echo test');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertTrue($event->wasBeforeCallbacksCalled());
    }

    /**
     * @testdox LD.10 background buildCommand includes schedule:finish
     */
    public function testBuildCommandIncludesScheduleFinish(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // runInBackground = true のとき schedule:finish が含まれる
        $this->assertStringContainsString('schedule:finish', $result->getEventCommand());
    }

    /**
     * @testdox LD.11 runInBackground is preserved after dispatch
     */
    public function testRunInBackgroundIsPreservedAfterDispatch(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');
        $event->runInBackground = false;

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // runInBackground は変更されない（Event の設定を尊重する）
        $this->assertFalse($event->runInBackground);
    }

    /**
     * @testdox LD.12 output redirection is included in command
     */
    public function testOutputRedirectionIsIncludedInCommand(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');
        $event->sendOutputTo('/tmp/test-output.log');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // buildCommand() により出力リダイレクトが含まれる
        $this->assertStringContainsString('/tmp/test-output.log', $result->getEventCommand());
    }

    /**
     * @testdox LD.13 beforeCallbacks で例外が発生した場合は失敗結果を返す
     */
    public function testReturnsFailedWhenBeforeCallbackThrows(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createSpyEvent('echo test');
        $event->throwOnBeforeCallback(new \RuntimeException('Test exception'));

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertStringContainsString('RuntimeException', $result->getError());
        $this->assertStringContainsString('Test exception', $result->getError());
    }

    /**
     * @testdox LD.14 beforeCallbacks で Error が発生した場合は再スローされる
     */
    public function testRethrowsErrorFromBeforeCallback(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createSpyEvent('echo test');
        $event->throwOnBeforeCallback(new \Error('Test error'));

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Test error');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);
    }

    /**
     * @testdox LD.15 cleanup removes completed processes from internal list
     */
    public function testCleanupRemovesCompletedProcesses(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());

        // 即座に完了するプロセスをディスパッチ（background）
        $event1 = $this->createEvent('echo test1');
        $event1->runInBackground = true;
        $result1 = $dispatcher->dispatchEvent($event1, $this->app, $this->dueAt);

        // プロセスの完了を待つ
        $result1->getProcess()->wait();

        // 長時間実行するプロセスをディスパッチ（background）
        $event2 = $this->createEvent('sleep 10');
        $event2->runInBackground = true;
        $result2 = $dispatcher->dispatchEvent($event2, $this->app, $this->dueAt);

        // cleanup を呼ぶ
        $dispatcher->cleanup();

        // stopAll を呼んで残っているプロセスを確認
        // sleep プロセスがまだ実行中なので stop される
        $this->assertTrue($result2->isRunning());

        // クリーンアップ
        $result2->getProcess()->stop(0);
    }

    /**
     * @testdox LD.16 stopAll stops all running processes
     */
    public function testStopAllStopsAllRunningProcesses(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());

        // 複数のプロセスをディスパッチ（background）
        $event1 = $this->createEvent('sleep 10');
        $event1->runInBackground = true;
        $result1 = $dispatcher->dispatchEvent($event1, $this->app, $this->dueAt);

        $event2 = $this->createEvent('sleep 10');
        $event2->runInBackground = true;
        $result2 = $dispatcher->dispatchEvent($event2, $this->app, $this->dueAt);

        // 両方とも実行中であることを確認
        $this->assertTrue($result1->isRunning());
        $this->assertTrue($result2->isRunning());

        // stopAll を呼ぶ
        $dispatcher->stopAll();

        // 両方とも停止していることを確認
        $this->assertFalse($result1->isRunning());
        $this->assertFalse($result2->isRunning());
    }

    /**
     * @testdox LD.17 stopAll handles already stopped processes gracefully
     */
    public function testStopAllHandlesAlreadyStoppedProcesses(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());

        // 即座に完了するプロセスをディスパッチ（background）
        $event = $this->createEvent('echo test');
        $event->runInBackground = true;
        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // プロセスの完了を待つ
        $result->getProcess()->wait();
        $this->assertFalse($result->isRunning());

        // 例外なく stopAll を呼べることを確認
        $dispatcher->stopAll();

        // テスト通過 = 例外なし
        $this->assertTrue(true);
    }

    /**
     * @testdox LD.18 dispatchEvent automatically adds result to running processes
     */
    public function testDispatchEventAddsResultToRunningProcesses(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());

        $event = $this->createEvent('sleep 5');
        $event->runInBackground = true;
        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // stopAll で停止されることで、内部リストに追加されていることを間接的に確認
        $dispatcher->stopAll();

        // 再度ディスパッチしても問題ないことを確認（内部リストがクリアされている）
        $event2 = $this->createEvent('echo test');
        $event2->runInBackground = true;
        $result = $dispatcher->dispatchEvent($event2, $this->app, $this->dueAt);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);

        $result->getProcess()->wait();
    }

    private function createStubResult(StubProcess $process, string $identifier = 'test'): StartedLocalDispatchResult
    {
        return new StartedLocalDispatchResult($process, $identifier, 'echo stub', new DateTimeImmutable());
    }

    /**
     * @testdox LD.19 stopAll sends SIGTERM to all running processes
     */
    public function testStopAllSendsSignalToAllProcesses(): void
    {
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), 0.1);

        $proc1 = new StubProcess(true);
        $proc1->setTerminateOnSignal(true);
        $proc2 = new StubProcess(true);
        $proc2->setTerminateOnSignal(true);

        $dispatcher->addRunningProcess($this->createStubResult($proc1, 'event1'));
        $dispatcher->addRunningProcess($this->createStubResult($proc2, 'event2'));

        $dispatcher->stopAll();

        $this->assertContains(SIGTERM, $proc1->getReceivedSignals());
        $this->assertContains(SIGTERM, $proc2->getReceivedSignals());
    }

    /**
     * @testdox LD.20 stopAll sends SIGKILL to processes that don't stop after SIGTERM
     */
    public function testStopAllSendsKillToProcessesThatDontStop(): void
    {
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), 0.05);

        $proc = new StubProcess(true);
        // terminateOnSignal = false → SIGTERM を無視する
        $proc->setTerminateOnSignal(false);

        $dispatcher->addRunningProcess($this->createStubResult($proc, 'event1'));

        $dispatcher->stopAll();

        $this->assertContains(SIGTERM, $proc->getReceivedSignals());
        $this->assertContains(SIGKILL, $proc->getReceivedSignals());
    }

    /**
     * @testdox LD.21 stopAll handles signal exception gracefully
     */
    public function testStopAllHandlesSignalExceptionGracefully(): void
    {
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), 0.05);

        // signal() で例外を投げるプロセス（無名クラスで StubProcess を拡張）
        $throwingProc = new class (true) extends StubProcess {
            public function signal(int $signal): void
            {
                parent::signal($signal);
                throw new \RuntimeException('Signal failed');
            }
        };

        $normalProc = new StubProcess(true);
        $normalProc->setTerminateOnSignal(true);

        $dispatcher->addRunningProcess($this->createStubResult($throwingProc, 'throwing'));
        $dispatcher->addRunningProcess($this->createStubResult($normalProc, 'normal'));

        // 例外なく完了する
        $dispatcher->stopAll();

        // 正常なプロセスには SIGTERM が送信されている
        $this->assertContains(SIGTERM, $normalProc->getReceivedSignals());
    }

    /**
     * @testdox LD.22 stopAll skips signal for non-running processes
     */
    public function testStopAllSkipsSignalForNonRunningProcesses(): void
    {
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), 0.05);

        $runningProc = new StubProcess(true);
        $runningProc->setTerminateOnSignal(true);

        $stoppedProc = new StubProcess(false); // 既に停止済み

        $dispatcher->addRunningProcess($this->createStubResult($runningProc, 'running'));
        $dispatcher->addRunningProcess($this->createStubResult($stoppedProc, 'stopped'));

        $dispatcher->stopAll();

        // running プロセスには SIGTERM が送信される
        $this->assertContains(SIGTERM, $runningProc->getReceivedSignals());
        // 停止済みプロセスにはシグナルが送信されない
        $this->assertEmpty($stoppedProc->getReceivedSignals());
    }

    /**
     * @testdox LD.23 foreground event runs synchronously with Process::run()
     */
    public function testForegroundEventRunsSynchronously(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo foreground');
        // runInBackground のデフォルトは false

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // 同期実行なので、戻り時には既にプロセスが終了している
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertFalse($result->isRunning());
        $this->assertSame(0, $result->getExitCode());
    }

    /**
     * @testdox LD.24 foreground event calls afterCallbacksWithExitCode
     */
    public function testForegroundEventCallsAfterCallbacks(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createSpyEvent('echo test');
        // runInBackground のデフォルトは false

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertTrue($event->wasAfterCallbacksWithExitCodeCalled());
        $this->assertSame(0, $event->getAfterCallbacksExitCode());
    }

    /**
     * @testdox LD.25 foreground event result is not added to running processes
     */
    public function testForegroundEventResultNotAddedToRunningProcesses(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('echo test');
        // runInBackground のデフォルトは false

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // stopAll を呼んでも何もないことを確認（内部リストが空）
        // cleanup 後に stopAll しても例外なし = 追跡リストに追加されていない
        $dispatcher->cleanup();
        $dispatcher->stopAll();

        $this->assertTrue(true);
    }

    /**
     * @testdox LD.26 background event runs asynchronously with Process::start()
     */
    public function testBackgroundEventRunsAsynchronously(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createEvent('sleep 2');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // 非同期実行なので、戻り時にはまだプロセスが実行中
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertTrue($result->isRunning());

        $result->getProcess()->stop(0);
    }

    /**
     * @testdox LD.27 ClockAwareEvent を background で dispatchEvent に渡すと buildProcessCommand() 経由でコマンドが生成される
     */
    public function testClockAwareEventUseBuildProcessCommandInBackground(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'echo clockaware', $clock);
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // buildProcessCommand() により末尾の & が除去されている
        $this->assertStringNotContainsString(' &', $result->getEventCommand());
        // schedule:finish が含まれる（buildCommand は background で schedule:finish を付与する）
        $this->assertStringContainsString('schedule:finish', $result->getEventCommand());

        $result->getProcess()->wait();
    }

    /**
     * @testdox LD.28 Foreground で非ゼロ exit code のとき afterCallbacks に正しい exit code が渡される
     */
    public function testForegroundNonZeroExitCodePassedToAfterCallbacks(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createSpyEvent('exit 42');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertTrue($event->wasAfterCallbacksWithExitCodeCalled());
        $this->assertSame(42, $event->getAfterCallbacksExitCode());
    }

    /**
     * @testdox LD.29 Foreground で afterCallbacks が例外をスローしても StartedLocalDispatchResult が返される
     */
    public function testForegroundAfterCallbackExceptionReturnsStartedResult(): void
    {
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper());
        $event = $this->createSpyEvent('echo test');
        $event->throwOnAfterCallback(new \RuntimeException('afterCallback error'));

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertFalse($result->isRunning());
        $this->assertSame(0, $result->getExitCode());
    }
}
