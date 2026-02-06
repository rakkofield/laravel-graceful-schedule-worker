<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\ThrowingFakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

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
     * @testdox T2.11
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
            'local'
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
     * @testdox T2.12
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
            'local'
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
     * @testdox T2.13
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
            'local'
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
     * @testdox T2.14
     */
    public function testThrowsOnUnknownType(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            'local'
        );

        $event = $this->createClockAwareEvent('echo test', 'unknown');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dispatcher type: unknown. Available types: local');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);
    }

    /**
     * @testdox T2.15
     */
    public function testReceivesDefaultTypeViaConstructor(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            'local'
        );

        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox T2.16
     */
    public function testThrowsWhenDispatchersArrayIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dispatchers array cannot be empty');

        new CompositeDispatcher([], 'local');
    }

    /**
     * @testdox T2.17
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
            'nonexistent'
        );
    }

    /**
     * @testdox T2.18 cleanup が全子ディスパッチャーに委譲される
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
            'local'
        );

        $dispatcher->cleanup();

        $this->assertEquals(1, $localDispatcher->getCleanupCallCount());
        $this->assertEquals(1, $sfnDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox T2.19 stopAll が全子ディスパッチャーに委譲される
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
            'local'
        );

        $dispatcher->stopAll();

        $this->assertEquals(1, $localDispatcher->getStopAllCallCount());
        $this->assertEquals(1, $sfnDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox T2.20 cleanup が1つの子で例外が発生しても他の子に委譲される
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
            'local'
        );

        // 例外がスローされないこと
        $dispatcher->cleanup();

        // 両方の cleanup が呼ばれていること
        $this->assertEquals(1, $throwingDispatcher->getCleanupCallCount());
        $this->assertEquals(1, $normalDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox T2.21 stopAll が1つの子で例外が発生しても他の子に委譲される
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
            'local'
        );

        // 例外がスローされないこと
        $dispatcher->stopAll();

        // 両方の stopAll が呼ばれていること
        $this->assertEquals(1, $throwingDispatcher->getStopAllCallCount());
        $this->assertEquals(1, $normalDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox T2.22 cleanup で例外発生時にログが出力される
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
     * @testdox T2.23 stopAll で例外発生時にログが出力される
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
