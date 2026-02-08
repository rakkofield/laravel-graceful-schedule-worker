<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeAlreadyRunningDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeFailedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\StubThrowingExecutionTracker;

class TrackingDispatcherTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var FakeDispatcher */
    private $innerDispatcher;

    /** @var FakeExecutionTracker */
    private $tracker;

    /** @var SpyLogger */
    private $logger;

    /** @var TrackingDispatcher */
    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->mutex = new FakeEventMutex();
        $this->container->bind(EventMutex::class, function () {
            return $this->mutex;
        });

        $this->tracker = new FakeExecutionTracker();
        $this->logger = new SpyLogger();
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

    private function createDispatcher(DispatchResultInterface $resultToReturn): TrackingDispatcher
    {
        $this->innerDispatcher = new FakeDispatcher($resultToReturn);
        return new TrackingDispatcher(
            $this->innerDispatcher,
            $this->tracker,
            $this->logger
        );
    }

    /**
     * @testdox TD.1 ロック取得成功時に内部 Dispatcher に委譲される
     */
    public function testDelegatesToInnerDispatcherWhenLockAcquired(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $result = $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertSame($startedResult, $result);
        $this->assertCount(1, $this->innerDispatcher->getDispatched());
        $dispatched = $this->innerDispatcher->getDispatched()[0];
        $this->assertSame($event, $dispatched['event']);
        $this->assertSame($this->container, $dispatched['container']);
        $this->assertSame($dueAt, $dispatched['dueAt']);
    }

    /**
     * @testdox TD.2 ロック取得失敗時に SkippedDispatchResult を返す
     */
    public function testReturnsSkippedWhenLockNotAcquired(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // ロック取得失敗を設定
        $this->tracker->setLockResult($event->mutexName(), $dueAt, false);

        $result = $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(SkippedDispatchResult::class, $result);
        $this->assertSame('lock_not_acquired', $result->getReason());
        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
        $this->assertSame('echo test', $result->getEventCommand());

        // 内部 Dispatcher は呼ばれない
        $this->assertCount(0, $this->innerDispatcher->getDispatched());
    }

    /**
     * @testdox TD.3 Started 結果で markExecuted が呼ばれる
     */
    public function testMarksExecutedOnStartedResult(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $executed = $this->tracker->getExecuted();
        $this->assertArrayHasKey($event->mutexName(), $executed);
        $this->assertSame($dueAt, $executed[$event->mutexName()]);
    }

    /**
     * @testdox TD.4 AlreadyRunning 結果で markExecuted が呼ばれる
     */
    public function testMarksExecutedOnAlreadyRunningResult(): void
    {
        $alreadyRunningResult = FakeAlreadyRunningDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($alreadyRunningResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $executed = $this->tracker->getExecuted();
        $this->assertArrayHasKey($event->mutexName(), $executed);
        $this->assertSame($dueAt, $executed[$event->mutexName()]);
    }

    /**
     * @testdox TD.5 Failed 結果でエラーログが出力される
     */
    public function testLogsErrorOnFailedResult(): void
    {
        $failedResult = FakeFailedDispatchResult::create(
            'test-mutex',
            'echo test',
            'Test error message',
            'fake'
        );
        $dispatcher = $this->createDispatcher($failedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        // markExecuted は呼ばれない
        $executed = $this->tracker->getExecuted();
        $this->assertArrayNotHasKey($event->mutexName(), $executed);

        // エラーログが出力される
        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertStringContainsString('Failed to dispatch event', $errorLogs[0]['message']);
        $this->assertSame($event->mutexName(), $errorLogs[0]['context']['event']);
        $this->assertSame('Test error message', $errorLogs[0]['context']['error']);
    }

    /**
     * @testdox TD.6 Failed 結果に例外がある場合はコンテキストに含まれる
     */
    public function testLogsExceptionInContextOnFailedResult(): void
    {
        $exception = new \RuntimeException('Test exception');
        $failedResult = FakeFailedDispatchResult::create(
            'test-mutex',
            'echo test',
            'Test error message',
            'fake',
            $exception
        );
        $dispatcher = $this->createDispatcher($failedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertSame($exception, $errorLogs[0]['context']['exception']);
    }

    /**
     * @testdox TD.7 予期しない結果型で LogicException がスローされる
     */
    public function testThrowsLogicExceptionOnUnexpectedResultType(): void
    {
        // DispatchResultInterface を実装するが、既知のインターフェースを実装しないクラス
        $unexpectedResult = new class implements DispatchResultInterface {
            public function getEventIdentifier(): string
            {
                return 'test';
            }

            public function getEventCommand(): string
            {
                return 'test';
            }

            public function getDispatcherType(): string
            {
                return 'unexpected';
            }

            public function getDispatchedAt(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };

        $this->innerDispatcher = new FakeDispatcher($unexpectedResult);
        $dispatcher = new TrackingDispatcher(
            $this->innerDispatcher,
            $this->tracker,
            $this->logger
        );
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unexpected dispatch result type');

        $dispatcher->dispatchEvent($event, $this->container, $dueAt);
    }

    /**
     * @testdox TD.8 cleanup が内部 Dispatcher に委譲される
     */
    public function testCleanupDelegatesToInnerDispatcher(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);

        $dispatcher->cleanup();

        $this->assertSame(1, $this->innerDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox TD.9 stopAll が内部 Dispatcher に委譲される
     */
    public function testStopAllDelegatesToInnerDispatcher(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);

        $dispatcher->stopAll();

        $this->assertSame(1, $this->innerDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox TD.10 SkippedDispatchResult の dispatcherType は 'tracking' を返す
     */
    public function testSkippedDispatchResultReturnsTrackingType(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // ロック取得失敗を設定
        $this->tracker->setLockResult($event->mutexName(), $dueAt, false);

        $result = $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertSame('tracking', $result->getDispatcherType());
    }

    /**
     * @testdox TD.11 ロック取得失敗時に DEBUG ログが出力される
     */
    public function testLogsDebugWhenLockNotAcquired(): void
    {
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $dispatcher = $this->createDispatcher($startedResult);
        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // ロック取得失敗を設定
        $this->tracker->setLockResult($event->mutexName(), $dueAt, false);

        $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        // DEBUG ログが出力されること
        $debugLogs = $this->logger->getLogsByLevel('debug');
        $this->assertCount(1, $debugLogs);
        $this->assertStringContainsString('Lock not acquired, skipping dispatch', $debugLogs[0]['message']);
        $this->assertSame($event->mutexName(), $debugLogs[0]['context']['event']);
        $this->assertSame('2024-01-15T10:00:00+00:00', $debugLogs[0]['context']['dueAt']);
    }

    /**
     * @testdox TD.12 markExecuted の例外はキャッチされ warning ログが出力される
     */
    public function testCatchesMarkExecutedExceptionAndLogsWarning(): void
    {
        $exception = new \RuntimeException('Redis connection lost');
        $throwingTracker = new StubThrowingExecutionTracker($exception);
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $innerDispatcher = new FakeDispatcher($startedResult);
        $dispatcher = new TrackingDispatcher($innerDispatcher, $throwingTracker, $this->logger);

        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // 例外がスローされず、結果が返される
        $result = $dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertSame($startedResult, $result);

        // warning ログが出力される
        $warningLogs = $this->logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('Failed to track execution result', $warningLogs[0]['message']);
        $this->assertSame($event->mutexName(), $warningLogs[0]['context']['event']);
        $this->assertSame('Redis connection lost', $warningLogs[0]['context']['error']);
        $this->assertSame($exception, $warningLogs[0]['context']['exception']);
    }

    /**
     * @testdox TD.13 handleResult で LogicException がスローされた場合はキャッチされず再スローされる
     */
    public function testLogicExceptionFromHandleResultIsRethrown(): void
    {
        // LogicException をスローする tracker
        $logicException = new \LogicException('Programming error');
        $throwingTracker = new StubThrowingExecutionTracker($logicException);
        $startedResult = FakeStartedDispatchResult::create('test-mutex', 'echo test', 'fake');
        $innerDispatcher = new FakeDispatcher($startedResult);
        $dispatcher = new TrackingDispatcher($innerDispatcher, $throwingTracker, $this->logger);

        $event = $this->createEvent('echo test');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Programming error');

        $dispatcher->dispatchEvent($event, $this->container, $dueAt);
    }
}
