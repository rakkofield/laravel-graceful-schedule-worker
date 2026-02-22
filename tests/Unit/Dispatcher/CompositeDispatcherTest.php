<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;
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

    private function createClockAwareEvent(string $command, ?string $dispatcherType = null): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, $command, $clock, 'local', null, new TimezoneResolver());
        if ($dispatcherType !== null) {
            $event->dispatchVia($dispatcherType);
        }
        return $event;
    }

    /**
     * @testdox CD.1 Delegates to the event-specified dispatcher
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
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test', 'stepfunctions');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame('stepfunctions', $result->getDispatcherType());
        $this->assertEquals(1, $sfnDispatcher->getDispatchCount());
        $this->assertEquals(0, $localDispatcher->getDispatchCount());
    }

    /**
     * @testdox CD.2 Default dispatcher type uses local dispatcher
     */
    public function testDefaultDispatcherTypeUsesLocalDispatcher(): void
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
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame('local', $result->getDispatcherType());
        $this->assertEquals(1, $localDispatcher->getDispatchCount());
        $this->assertEquals(0, $sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox CD.3 ClockAwareEvent uses its own dispatcherType
     */
    public function testClockAwareEventUsesOwnDispatcherType(): void
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
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test', null);

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame('local', $result->getDispatcherType());
        $this->assertEquals(1, $localDispatcher->getDispatchCount());
        $this->assertEquals(0, $sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox CD.4 Throws exception on unknown dispatcher type
     */
    public function testThrowsOnUnknownType(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test', 'unknown');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dispatcher type: unknown. Available types: local');

        $dispatcher->dispatchEvent($event, $this->dueAt);
    }

    /**
     * @testdox CD.5 Constructor takes dispatchers and logger only
     */
    public function testConstructorTakesDispatchersAndLoggerOnly(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            $this->logger
        );

        $event = $this->createClockAwareEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox CD.6 Throws exception when dispatchers array is empty
     */
    public function testThrowsWhenDispatchersArrayIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dispatchers array cannot be empty');

        new CompositeDispatcher([], $this->logger);
    }

    /**
     * @testdox CD.8 cleanup delegates to all child dispatchers
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
            $this->logger
        );

        $dispatcher->cleanup();

        $this->assertEquals(1, $localDispatcher->getCleanupCallCount());
        $this->assertEquals(1, $sfnDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox CD.9 stopAll delegates to all child dispatchers
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
            $this->logger
        );

        $dispatcher->stopAll();

        $this->assertEquals(1, $localDispatcher->getStopAllCallCount());
        $this->assertEquals(1, $sfnDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox CD.10 cleanup delegates to other children even when one child throws
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
            $this->logger
        );

        // No exception is thrown
        $dispatcher->cleanup();

        // Both cleanup methods were called
        $this->assertEquals(1, $throwingDispatcher->getCleanupCallCount());
        $this->assertEquals(1, $normalDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox CD.11 stopAll delegates to other children even when one child throws
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
            $this->logger
        );

        // No exception is thrown
        $dispatcher->stopAll();

        // Both stopAll methods were called
        $this->assertEquals(1, $throwingDispatcher->getStopAllCallCount());
        $this->assertEquals(1, $normalDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox CD.12 Logs warning when cleanup throws an exception
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
            $logger
        );

        $dispatcher->cleanup();

        // Log is output
        $warningLogs = $logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('Failed to cleanup dispatcher', $warningLogs[0]['message']);
        $this->assertSame('local', $warningLogs[0]['context']['dispatcher']);
        $this->assertSame('Cleanup failed', $warningLogs[0]['context']['error']);
    }

    /**
     * @testdox CD.14 cleanup isolates \Error from one dispatcher
     */
    public function testCleanupIsolatesErrorFromOneDispatcher(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $throwingDispatcher = new ThrowingFakeDispatcher($localResult);
        $throwingDispatcher->willThrowOnCleanup(new \Error('Fatal cleanup error'));
        $normalDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $throwingDispatcher,
                'stepfunctions' => $normalDispatcher,
            ],
            $this->logger
        );

        // No error is thrown
        $dispatcher->cleanup();

        // Both cleanup methods were called
        $this->assertEquals(1, $throwingDispatcher->getCleanupCallCount());
        $this->assertEquals(1, $normalDispatcher->getCleanupCallCount());
    }

    /**
     * @testdox CD.15 stopAll isolates \Error from one dispatcher
     */
    public function testStopAllIsolatesErrorFromOneDispatcher(): void
    {
        $localResult = FakeStartedDispatchResult::create('local-id', 'cmd', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-id', 'cmd', 'stepfunctions');

        $throwingDispatcher = new ThrowingFakeDispatcher($localResult);
        $throwingDispatcher->willThrowOnStopAll(new \Error('Fatal stopAll error'));
        $normalDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $throwingDispatcher,
                'stepfunctions' => $normalDispatcher,
            ],
            $this->logger
        );

        // No error is thrown
        $dispatcher->stopAll();

        // Both stopAll methods were called
        $this->assertEquals(1, $throwingDispatcher->getStopAllCallCount());
        $this->assertEquals(1, $normalDispatcher->getStopAllCallCount());
    }

    /**
     * @testdox CD.13 Logs error when stopAll throws an exception
     */
    public function testStopAllLogsErrorWhenChildThrows(): void
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
            $logger
        );

        $dispatcher->stopAll();

        // Log is output
        $errorLogs = $logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertStringContainsString('Failed to stop dispatcher', $errorLogs[0]['message']);
        $this->assertSame('local', $errorLogs[0]['context']['dispatcher']);
        $this->assertSame('StopAll failed', $errorLogs[0]['context']['error']);
    }
}
