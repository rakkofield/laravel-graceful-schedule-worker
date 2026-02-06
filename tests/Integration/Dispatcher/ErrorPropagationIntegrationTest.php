<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\ThrowingFakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\ThrowingFakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;

/**
 * エラー伝播と graceful degradation の統合テスト
 */
class ErrorPropagationIntegrationTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->mutex = new FakeEventMutex();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox T12.1 markExecuted failure logs warning but returns result
     */
    public function testMarkExecutedFailureLogsWarningButReturnsResult(): void
    {
        $lockProvider = new FakeLockProvider();
        $throwingCache = new ThrowingFakeCacheStore($lockProvider);
        $throwingCache->willThrowOnPut(new \RuntimeException('Redis connection lost'));

        $logger = new SpyLogger();
        $tracker = new CacheExecutionTracker($throwingCache, $lockProvider, $logger);

        $innerResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $innerDispatcher = new FakeDispatcher($innerResult);
        $trackingDispatcher = new TrackingDispatcher($innerDispatcher, $tracker, $logger);

        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'echo test', $clock);
        $event->cron('0 * * * *');
        $dueAt = Carbon::parse('2024-01-15 12:00:00');

        $result = $trackingDispatcher->dispatchEvent($event, $this->container, $dueAt);

        // dispatch 結果自体は Started のまま返る
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame($innerResult, $result);

        // warning ログが記録される
        $this->assertTrue($logger->hasLogContaining('warning', 'Failed to track execution result'));
    }

    /**
     * @testdox T12.2 Inner dispatcher exception bubbles up
     */
    public function testInnerDispatcherExceptionBubblesUp(): void
    {
        $lockProvider = new FakeLockProvider();
        $cache = new ThrowingFakeCacheStore($lockProvider);
        $logger = new SpyLogger();
        $tracker = new CacheExecutionTracker($cache, $lockProvider, $logger);

        $throwingInner = new class implements ScheduleDispatcherInterface {
            /**
             * @param \Illuminate\Console\Scheduling\Event $event
             * @param Container $container
             * @param \DateTimeInterface $dueAt
             * @return DispatchResultInterface
             */
            public function dispatchEvent(
                \Illuminate\Console\Scheduling\Event $event,
                Container $container,
                \DateTimeInterface $dueAt
            ): DispatchResultInterface {
                throw new \RuntimeException('boom');
            }

            public function cleanup(): void
            {
            }

            public function stopAll(): void
            {
            }
        };

        $trackingDispatcher = new TrackingDispatcher($throwingInner, $tracker, $logger);

        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'echo test', $clock);
        $event->cron('0 * * * *');
        $dueAt = Carbon::parse('2024-01-15 12:00:00');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $trackingDispatcher->dispatchEvent($event, $this->container, $dueAt);
    }

    /**
     * @testdox T12.3 Composite cleanup continues on child failure
     */
    public function testCompositeCleanupContinuesOnChildFailure(): void
    {
        $normalResult = FakeStartedDispatchResult::create('n', 'echo n', 'type2');
        $throwResult = FakeStartedDispatchResult::create('t', 'echo t', 'type1');

        $normalDispatcher = new FakeDispatcher($normalResult);
        $throwingDispatcher = new ThrowingFakeDispatcher($throwResult);
        $throwingDispatcher->willThrowOnCleanup(new \RuntimeException('cleanup failed'));

        $logger = new SpyLogger();
        $composite = new CompositeDispatcher(
            ['type1' => $throwingDispatcher, 'type2' => $normalDispatcher],
            'type1',
            $logger
        );

        $composite->cleanup();

        $this->assertSame(1, $throwingDispatcher->getCleanupCallCount());
        $this->assertSame(1, $normalDispatcher->getCleanupCallCount());
        $this->assertTrue($logger->hasLogContaining('warning', 'Failed to cleanup dispatcher'));
    }

    /**
     * @testdox T12.4 Composite stopAll continues on child failure
     */
    public function testCompositeStopAllContinuesOnChildFailure(): void
    {
        $normalResult = FakeStartedDispatchResult::create('n', 'echo n', 'type2');
        $throwResult = FakeStartedDispatchResult::create('t', 'echo t', 'type1');

        $normalDispatcher = new FakeDispatcher($normalResult);
        $throwingDispatcher = new ThrowingFakeDispatcher($throwResult);
        $throwingDispatcher->willThrowOnStopAll(new \RuntimeException('stop failed'));

        $logger = new SpyLogger();
        $composite = new CompositeDispatcher(
            ['type1' => $throwingDispatcher, 'type2' => $normalDispatcher],
            'type1',
            $logger
        );

        $composite->stopAll();

        $this->assertSame(1, $throwingDispatcher->getStopAllCallCount());
        $this->assertSame(1, $normalDispatcher->getStopAllCallCount());
        $this->assertTrue($logger->hasLogContaining('warning', 'Failed to stop dispatcher'));
    }
}
