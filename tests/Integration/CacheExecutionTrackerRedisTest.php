<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Carbon\Carbon;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeRedisFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use Redis;

/**
 * CacheExecutionTracker の Redis 統合テスト
 *
 * @requires extension redis
 */
class CacheExecutionTrackerRedisTest extends TestCase
{
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

    /**
     * @return bool
     */
    private function isRedisAvailable(): bool
    {
        try {
            $redis = new Redis();
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            $redis->connect($host, $port, 1.0);
            $redis->ping();
            $redis->close();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return Repository
     */
    private function createRedisCache(): Repository
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $factory = new FakeRedisFactory($host, $port);
        $store = new RedisStore($factory, 'test:');

        return new Repository($store);
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
        $tracker = new CacheExecutionTracker($this->cache, $this->logger);
        $event = $this->createEvent('php artisan test:redis-task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $tracker->markExecuted($event, $dueAt);

        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
        // Redis returns values as strings
        $this->assertEquals($dueAt->timestamp, $this->cache->get($key));
    }

    /**
     * @testdox T7.2 acquireLock uses Redis atomic lock
     */
    public function testAcquireLockUsesRedisAtomicLock(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->logger);
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
        $tracker = new CacheExecutionTracker($this->cache, $this->logger);
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
        $tracker = new CacheExecutionTracker($this->cache, $this->logger);
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
        $tracker = new CacheExecutionTracker($this->cache, $this->logger);
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
}
