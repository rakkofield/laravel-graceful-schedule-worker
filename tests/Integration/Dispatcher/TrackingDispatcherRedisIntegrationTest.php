<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeAlreadyRunningDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeFailedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\RedisTestTrait;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;

/**
 * TrackingDispatcher + CacheExecutionTracker + Redis の統合テスト
 *
 * @requires extension redis
 */
class TrackingDispatcherRedisIntegrationTest extends TestCase
{
    use RedisTestTrait;

    /** @var Repository */
    private $cache;

    /** @var CacheExecutionTracker */
    private $tracker;

    /** @var FakeDispatcher */
    private $innerDispatcher;

    /** @var TrackingDispatcher */
    private $dispatcher;

    /** @var SpyLogger */
    private $logger;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->mutex = new FakeEventMutex();
        $this->logger = new SpyLogger();

        $this->cache = $this->createRedisCache('test:tracking:');
        $this->cache->flush();

        $store = $this->cache->getStore();
        $this->tracker = new CacheExecutionTracker($this->cache, $store, $this->logger);

        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->innerDispatcher = new FakeDispatcher($defaultResult);
        $this->dispatcher = new TrackingDispatcher($this->innerDispatcher, $this->tracker, $this->logger);
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->flush();
        }
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param string $command
     * @return ClockAwareEvent
     */
    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock);
    }

    /**
     * @testdox T8.1 Lock acquired and markExecuted on success with Redis
     */
    public function testLockAcquiredAndMarkExecutedOnSuccess(): void
    {
        $event = $this->createEvent('echo test');
        $event->cron('0 * * * *');
        $dueAt = new DateTimeImmutable('2024-01-15 12:00:00');

        $this->innerDispatcher->setResult(
            FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake')
        );

        $result = $this->dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        // Redis に markExecuted 記録が存在
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
        $this->assertEquals($dueAt->getTimestamp(), $this->cache->get($key));
    }

    /**
     * @testdox T8.2 Second dispatch skipped by Redis lock
     */
    public function testSecondDispatchSkippedByRedisLock(): void
    {
        $event = $this->createEvent('echo test-lock');
        $event->cron('0 * * * *');
        $dueAt = new DateTimeImmutable('2024-01-15 12:00:00');

        $this->innerDispatcher->setResult(
            FakeStartedDispatchResult::create($event->mutexName(), 'echo test-lock', 'fake')
        );

        // 1回目: 成功
        $result1 = $this->dispatcher->dispatchEvent($event, $this->container, $dueAt);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result1);

        // 2回目: ロック取得失敗でスキップ
        $result2 = $this->dispatcher->dispatchEvent($event, $this->container, $dueAt);
        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result2);
        $this->assertSame('lock_not_acquired', $result2->getReason());
        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());
        $this->assertTrue($this->logger->hasLogContaining('debug', 'Lock not acquired'));
    }

    /**
     * @testdox T8.3 Failed dispatch does not markExecuted
     */
    public function testFailedDispatchDoesNotMarkExecuted(): void
    {
        $event = $this->createEvent('echo test-fail');
        $event->cron('0 * * * *');
        $dueAt = new DateTimeImmutable('2024-01-15 12:00:00');

        $this->innerDispatcher->setResult(
            FakeFailedDispatchResult::create($event->mutexName(), 'echo test-fail', 'dispatch error')
        );

        $result = $this->dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);

        // markExecuted 記録なし
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertFalse($this->cache->has($key));
    }

    /**
     * @testdox T8.4 AlreadyRunning result marks executed
     */
    public function testAlreadyRunningResultMarksExecuted(): void
    {
        $event = $this->createEvent('echo test-already');
        $event->cron('0 * * * *');
        $dueAt = new DateTimeImmutable('2024-01-15 12:00:00');

        $this->innerDispatcher->setResult(
            FakeAlreadyRunningDispatchResult::create($event->mutexName(), 'echo test-already', 'fake')
        );

        $result = $this->dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertInstanceOf(AlreadyRunningDispatchResultInterface::class, $result);

        // AlreadyRunning でも markExecuted される
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
    }

    /**
     * @testdox T8.5 Error log on failed dispatch
     */
    public function testErrorLogOnFailedDispatch(): void
    {
        $event = $this->createEvent('echo test-error-log');
        $event->cron('0 * * * *');
        $dueAt = new DateTimeImmutable('2024-01-15 12:00:00');

        $exception = new \RuntimeException('connection lost');
        $this->innerDispatcher->setResult(
            FakeFailedDispatchResult::create(
                $event->mutexName(),
                'echo test-error-log',
                'dispatch error',
                'fake',
                $exception
            )
        );

        $this->dispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertTrue($this->logger->hasLogContaining('error', 'Failed to dispatch event'));
        $errorLogs = $this->logger->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertSame($exception, $errorLogs[0]['context']['exception']);
    }

    /**
     * @testdox T8.6 Different dueAt allows same event dispatch
     */
    public function testDifferentDueAtAllowsSameEventDispatch(): void
    {
        $event = $this->createEvent('echo test-dueat');
        $event->cron('0 * * * *');
        $dueAt1 = new DateTimeImmutable('2024-01-15 12:00:00');
        $dueAt2 = new DateTimeImmutable('2024-01-15 13:00:00');

        $this->innerDispatcher->setResult(
            FakeStartedDispatchResult::create($event->mutexName(), 'echo test-dueat', 'fake')
        );

        $result1 = $this->dispatcher->dispatchEvent($event, $this->container, $dueAt1);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result1);

        // 異なる dueAt ならロックは独立
        $result2 = $this->dispatcher->dispatchEvent($event, $this->container, $dueAt2);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result2);

        $this->assertSame(2, $this->innerDispatcher->getDispatchCount());
    }
}
