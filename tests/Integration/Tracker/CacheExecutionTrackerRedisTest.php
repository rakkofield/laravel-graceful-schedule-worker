<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Carbon\Carbon;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\RedisTestTrait;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * CacheExecutionTracker の Redis 統合テスト
 *
 * @requires extension redis
 */
class CacheExecutionTrackerRedisTest extends TestCase
{
    use RedisTestTrait;

    /**
     * @var Repository
     */
    private $cache;

    /**
     * @var FakeEventMutex
     */
    private $mutex;

    /**
     * @var NullLogger
     */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isRedisAvailable()) {
            $this->markTestSkipped('Redis server is not available');
        }

        $this->mutex = new FakeEventMutex();
        $this->logger = new NullLogger();
        $this->cache = $this->createRedisCache();
        $this->cache->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->flush();
        }
        parent::tearDown();
    }

    /**
     * @param int $lockTtl
     * @return CacheExecutionTracker
     */
    private function createTracker(int $lockTtl = 3600): CacheExecutionTracker
    {
        $store = $this->cache->getStore();
        return new CacheExecutionTracker($this->cache, $store, $this->logger, $lockTtl);
    }

    /**
     * @param string $command
     * @return Event
     */
    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    /**
     * @param string $command
     * @param ClockInterface $clock
     * @return ClockAwareEvent
     */
    private function createClockAwareEvent(string $command, ClockInterface $clock): ClockAwareEvent
    {
        return new ClockAwareEvent($this->mutex, $command, $clock);
    }

    /**
     * @testdox T7.1 markExecuted stores data in Redis
     */
    public function testMarkExecutedStoresDataInRedis(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $tracker->markExecuted($event, $dueAt);

        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
        // assertEquals は型を無視して比較するため、Redis が文字列を返しても一致する
        $this->assertEquals($dueAt->timestamp, $this->cache->get($key));
    }

    /**
     * @testdox T7.2 acquireLock returns true on first call
     */
    public function testAcquireLockReturnsTrueOnFirstCall(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-lock');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($result);
    }

    /**
     * @testdox T7.3 Second acquireLock on same key fails
     */
    public function testSecondAcquireLockOnSameKeyFails(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-lock-conflict');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $first = $tracker->acquireLock($event, $dueAt);
        $second = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    /**
     * @testdox T7.4 acquireLock succeeds after releaseLock
     */
    public function testAcquireLockSucceedsAfterReleaseLock(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-lock-release');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $tracker->acquireLock($event, $dueAt);
        $tracker->releaseLock($event, $dueAt);
        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($result);
    }

    /**
     * @testdox T7.5 getMissedDueIfRecoverable retrieves data from Redis
     */
    public function testGetMissedDueIfRecoverableRetrievesDataFromRedis(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();
        $event = $this->createClockAwareEvent('php artisan test:redis-missed', $clock);
        $event->cron('0 * * * *'); // 毎時0分

        // 10:00 に実行記録
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        // 11:05 にチェック（11:00 が欠落している）
        $now = Carbon::parse('2024-01-15 11:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNotNull($result);
        $this->assertSame(11, $result->hour);
        $this->assertSame(0, $result->minute);
    }

    /**
     * @testdox T7.6 Concurrent lock acquisition across separate trackers fails
     */
    public function testConcurrentLockAcquisitionAcrossSeparateTrackersFails(): void
    {
        $tracker1 = $this->createTracker();
        $cache2 = $this->createRedisCache();
        $tracker2 = new CacheExecutionTracker($cache2, $cache2->getStore(), $this->logger);
        $event = $this->createEvent('php artisan test:concurrent');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $result1 = $tracker1->acquireLock($event, $dueAt);
        $result2 = $tracker2->acquireLock($event, $dueAt);

        $this->assertTrue($result1);
        $this->assertFalse($result2);
    }

    /**
     * @testdox T7.7 Lock expires after TTL allowing re-acquisition
     * @group slow
     */
    public function testLockExpiresAfterTtlAllowingReAcquisition(): void
    {
        $shortTtl = 2; // 2秒
        $tracker1 = $this->createTracker($shortTtl);
        $event = $this->createEvent('php artisan test:ttl-expiry');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $tracker1->acquireLock($event, $dueAt);

        sleep(3); // TTL より長く待機

        $cache2 = $this->createRedisCache();
        $tracker2 = new CacheExecutionTracker($cache2, $cache2->getStore(), $this->logger);
        $result = $tracker2->acquireLock($event, $dueAt);

        $this->assertTrue($result);
    }
}
