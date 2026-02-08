<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;

class CompositeDispatcherTest extends TestCase
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @var FakeEventMutex
     */
    private $mutex;

    /** @var DateTimeImmutable */
    private $dueAt;

    /** @var SpyLogger */
    private $logger;

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

    private function createClockAwareEvent(string $command, ?string $dispatcherType = null): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, $command, $clock);
        if ($dispatcherType !== null) {
            $event->dispatchVia($dispatcherType);
        }
        return $event;
    }

    /**
     * @testdox CD.1
     */
    public function testDelegatesToEventSpecifiedDispatcher(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local',
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test', 'stepfunctions');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame('stepfunctions', $result->getDispatcherType());
        $this->assertEquals(1, $sfnDispatcher->getDispatchCount());
        $this->assertEquals(0, $localDispatcher->getDispatchCount());
    }

    /**
     * @testdox CD.2
     */
    public function testUsesDefaultWhenNoEventSetting(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local',
            $this->logger
        );

        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame('local', $result->getDispatcherType());
        $this->assertEquals(1, $localDispatcher->getDispatchCount());
        $this->assertEquals(0, $sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox CD.3
     */
    public function testUsesDefaultWhenDispatcherTypeIsNull(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local',
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test', null);

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame('local', $result->getDispatcherType());
        $this->assertEquals(1, $localDispatcher->getDispatchCount());
        $this->assertEquals(0, $sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox CD.4
     */
    public function testThrowsOnUnknownType(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            'local',
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test', 'unknown');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dispatcher type: unknown. Available types: local');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);
    }

    /**
     * @testdox CD.5
     */
    public function testReceivesDefaultTypeViaConstructor(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            'local',
            $this->logger
        );

        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox CD.6
     */
    public function testThrowsWhenDispatchersArrayIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dispatchers array cannot be empty');

        new CompositeDispatcher([], 'local', $this->logger);
    }

    /**
     * @testdox CD.7
     */
    public function testThrowsWhenDefaultTypeNotInDispatchers(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Default dispatcher type 'nonexistent' not found in dispatchers. Available types: local"
        );

        new CompositeDispatcher(
            ['local' => $localDispatcher],
            'nonexistent',
            $this->logger
        );
    }

    /**
     * @testdox CD.8 cleanup が全子ディスパッチャーに委譲される
     */
    public function testCleanupDelegatesToAllChildDispatchers(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local',
            $this->logger
        );

        $dispatcher->cleanup();

        $this->assertEquals(1, $localDispatcher->getCleanupCallCount());
        $this->assertEquals(1, $sfnDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox CD.9 stopAll が全子ディスパッチャーに委譲される
     */
    public function testStopAllDelegatesToAllChildDispatchers(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local',
            $this->logger
        );

        $dispatcher->stopAll();

        $this->assertEquals(1, $localDispatcher->getStopAllCallCount());
        $this->assertEquals(1, $sfnDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox CD.10 cleanup が1つの子で例外が発生しても他の子に委譲される
     */
    public function testCleanupContinuesWhenChildThrows(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $throwingDispatcher = new ThrowingFakeDispatcher($localResult);
        $throwingDispatcher->willThrowOnCleanup(new \RuntimeException('Cleanup failed'));
        $normalDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $throwingDispatcher,
                'stepfunctions' => $normalDispatcher,
            ],
            'local',
            $this->logger
        );

        // 例外がスローされないこと
        $dispatcher->cleanup();

        // 両方の cleanup が呼ばれていること
        $this->assertEquals(1, $throwingDispatcher->getCleanupCallCount());
        $this->assertEquals(1, $normalDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox CD.11 stopAll が1つの子で例外が発生しても他の子に委譲される
     */
    public function testStopAllContinuesWhenChildThrows(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $throwingDispatcher = new ThrowingFakeDispatcher($localResult);
        $throwingDispatcher->willThrowOnStopAll(new \RuntimeException('StopAll failed'));
        $normalDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $throwingDispatcher,
                'stepfunctions' => $normalDispatcher,
            ],
            'local',
            $this->logger
        );

        // 例外がスローされないこと
        $dispatcher->stopAll();

        // 両方の stopAll が呼ばれていること
        $this->assertEquals(1, $throwingDispatcher->getStopAllCallCount());
        $this->assertEquals(1, $normalDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox CD.12 cleanup で例外発生時にログが出力される
     */
    public function testCleanupLogsWarningWhenChildThrows(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $throwingDispatcher = new ThrowingFakeDispatcher($localResult);
        $throwingDispatcher->willThrowOnCleanup(new \RuntimeException('Cleanup failed'));
        $normalDispatcher = new FakeDispatcher($sfnResult);

        $logger = new SpyLogger();
        $dispatcher = new CompositeDispatcher(
            [
                'local' => $throwingDispatcher,
                'stepfunctions' => $normalDispatcher,
            ],
            'local',
            $logger
        );

        $dispatcher->cleanup();

        // ログが出力されること
        $warningLogs = $logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('Failed to cleanup dispatcher', $warningLogs[0]['message']);
        $this->assertSame('local', $warningLogs[0]['context']['dispatcher']);
        $this->assertSame('Cleanup failed', $warningLogs[0]['context']['error']);
    }

    /**
     * @testdox CD.13 stopAll で例外発生時にログが出力される
     */
    public function testStopAllLogsWarningWhenChildThrows(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $throwingDispatcher = new ThrowingFakeDispatcher($localResult);
        $throwingDispatcher->willThrowOnStopAll(new \RuntimeException('StopAll failed'));
        $normalDispatcher = new FakeDispatcher($sfnResult);

        $logger = new SpyLogger();
        $dispatcher = new CompositeDispatcher(
            [
                'local' => $throwingDispatcher,
                'stepfunctions' => $normalDispatcher,
            ],
            'local',
            $logger
        );

        $dispatcher->stopAll();

        // ログが出力されること
        $warningLogs = $logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('Failed to stop dispatcher', $warningLogs[0]['message']);
        $this->assertSame('local', $warningLogs[0]['context']['dispatcher']);
        $this->assertSame('StopAll failed', $warningLogs[0]['context']['error']);
    }
}
