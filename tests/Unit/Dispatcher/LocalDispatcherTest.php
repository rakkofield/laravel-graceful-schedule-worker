<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyCallbackEvent;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
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
     * @testdox T2.1
     */
    public function testReturnsLocalDispatchResult(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
    }

    /**
     * @testdox T2.2
     */
    public function testReturnsStartedDispatchResultInterfaceOnSuccess(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox T2.3
     */
    public function testReturnsCorrectEventIdentifier(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
    }

    /**
     * @testdox T2.4
     */
    public function testReturnsCorrectEventCommand(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        // buildCommand() により元のコマンドが含まれたフルコマンドが返される
        $this->assertStringContainsString('php artisan report:daily', $result->getEventCommand());
    }

    /**
     * @testdox T2.5
     */
    public function testReturnsCorrectDispatcherType(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox T2.6
     */
    public function testStartsProcessInBackground(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('sleep 0.1');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertNotNull($result->getProcess());

        $result->getProcess()->wait();
    }

    /**
     * @testdox T2.7
     */
    public function testReturnsDispatchedAtTimestamp(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $before = new \DateTimeImmutable();
        $result = $dispatcher->dispatchEvent($event, $this->app);
        $after = new \DateTimeImmutable();

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertGreaterThanOrEqual($before, $dispatchedAt);
        $this->assertLessThanOrEqual($after, $dispatchedAt);
    }

    /**
     * @testdox T2.10
     */
    public function testHasRunningProcessImmediatelyAfterDispatch(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('sleep 2');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($result->isRunning());

        $result->getProcess()->stop(0);
    }

    /**
     * @testdox T2.11 beforeCallbacks are called before dispatch
     */
    public function testBeforeCallbacksAreCalledBeforeDispatch(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createSpyEvent('echo test');

        $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($event->wasBeforeCallbacksCalled());
    }

    /**
     * @testdox T2.12 buildCommand includes schedule:finish
     */
    public function testBuildCommandIncludesScheduleFinish(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        // buildCommand() が呼ばれると schedule:finish が含まれる
        $this->assertStringContainsString('schedule:finish', $result->getEventCommand());
    }

    /**
     * @testdox T2.13 runInBackground is set to true after dispatch
     */
    public function testRunInBackgroundIsSetAfterDispatch(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');
        $event->runInBackground = false;

        $dispatcher->dispatchEvent($event, $this->app);

        // runInBackground は true に変更される（イベントは1回しかディスパッチされないため復元不要）
        $this->assertTrue($event->runInBackground);
    }

    /**
     * @testdox T2.14 output redirection is included in command
     */
    public function testOutputRedirectionIsIncludedInCommand(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');
        $event->sendOutputTo('/tmp/test-output.log');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        // buildCommand() により出力リダイレクトが含まれる
        $this->assertStringContainsString('/tmp/test-output.log', $result->getEventCommand());
    }

    /**
     * @testdox T2.15 beforeCallbacks で例外が発生した場合は失敗結果を返す
     */
    public function testReturnsFailedWhenBeforeCallbackThrows(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createSpyEvent('echo test');
        $event->throwOnBeforeCallback(new \RuntimeException('Test exception'));

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertStringContainsString('RuntimeException', $result->getError());
        $this->assertStringContainsString('Test exception', $result->getError());
    }

    /**
     * @testdox T2.16 beforeCallbacks で Error が発生した場合は再スローされる
     */
    public function testRethrowsErrorFromBeforeCallback(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createSpyEvent('echo test');
        $event->throwOnBeforeCallback(new \Error('Test error'));

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Test error');

        $dispatcher->dispatchEvent($event, $this->app);
    }

    /**
     * @testdox T2.17 cleanup removes completed processes from internal list
     */
    public function testCleanupRemovesCompletedProcesses(): void
    {
        $dispatcher = new LocalDispatcher();

        // 即座に完了するプロセスをディスパッチ
        $event1 = $this->createEvent('echo test1');
        $result1 = $dispatcher->dispatchEvent($event1, $this->app);

        // プロセスの完了を待つ
        $result1->getProcess()->wait();

        // 長時間実行するプロセスをディスパッチ
        $event2 = $this->createEvent('sleep 10');
        $result2 = $dispatcher->dispatchEvent($event2, $this->app);

        // cleanup を呼ぶ
        $dispatcher->cleanup();

        // stopAll を呼んで残っているプロセスを確認
        // sleep プロセスがまだ実行中なので stop される
        $this->assertTrue($result2->isRunning());

        // クリーンアップ
        $result2->getProcess()->stop(0);
    }

    /**
     * @testdox T2.18 stopAll stops all running processes
     */
    public function testStopAllStopsAllRunningProcesses(): void
    {
        $dispatcher = new LocalDispatcher();

        // 複数のプロセスをディスパッチ
        $event1 = $this->createEvent('sleep 10');
        $result1 = $dispatcher->dispatchEvent($event1, $this->app);

        $event2 = $this->createEvent('sleep 10');
        $result2 = $dispatcher->dispatchEvent($event2, $this->app);

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
     * @testdox T2.19 stopAll handles already stopped processes gracefully
     */
    public function testStopAllHandlesAlreadyStoppedProcesses(): void
    {
        $dispatcher = new LocalDispatcher();

        // 即座に完了するプロセスをディスパッチ
        $event = $this->createEvent('echo test');
        $result = $dispatcher->dispatchEvent($event, $this->app);

        // プロセスの完了を待つ
        $result->getProcess()->wait();
        $this->assertFalse($result->isRunning());

        // 例外なく stopAll を呼べることを確認
        $dispatcher->stopAll();

        // テスト通過 = 例外なし
        $this->assertTrue(true);
    }

    /**
     * @testdox T2.20 dispatchEvent automatically adds result to running processes
     */
    public function testDispatchEventAddsResultToRunningProcesses(): void
    {
        $dispatcher = new LocalDispatcher();

        $event = $this->createEvent('sleep 5');
        $dispatcher->dispatchEvent($event, $this->app);

        // stopAll で停止されることで、内部リストに追加されていることを間接的に確認
        $dispatcher->stopAll();

        // 再度ディスパッチしても問題ないことを確認（内部リストがクリアされている）
        $event2 = $this->createEvent('echo test');
        $result = $dispatcher->dispatchEvent($event2, $this->app);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);

        $result->getProcess()->wait();
    }
}
