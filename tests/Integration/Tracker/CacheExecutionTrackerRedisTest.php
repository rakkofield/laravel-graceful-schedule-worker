<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeImmutable;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;

/**
 * CacheExecutionTracker Redis integration test
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
     * @param ClockInterface|null $clock
     * @return ClockAwareEvent
     */
    private function createEvent(string $command, ClockInterface $clock = null): ClockAwareEvent
    {
        $defaultClock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock ?? $defaultClock);
    }

    /**
     * @testdox CTI.1 markExecuted stores data in Redis
     */
    public function testMarkExecutedStoresDataInRedis(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $tracker->markExecuted($event, $dueAt);

        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
        // assertEquals ignores type, so it matches even if Redis returns a string
        $this->assertEquals($dueAt->getTimestamp(), $this->cache->get($key));
    }

    /**
     * @testdox CTI.2 acquireLock returns true on first call
     */
    public function testAcquireLockReturnsTrueOnFirstCall(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-lock');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($result);
    }

    /**
     * @testdox CTI.3 Second acquireLock on same key fails
     */
    public function testSecondAcquireLockOnSameKeyFails(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-lock-conflict');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $first = $tracker->acquireLock($event, $dueAt);
        $second = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    /**
     * @testdox CTI.4 acquireLock succeeds after releaseLock
     */
    public function testAcquireLockSucceedsAfterReleaseLock(): void
    {
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-lock-release');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $tracker->acquireLock($event, $dueAt);
        $tracker->releaseLock($event, $dueAt);
        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($result);
    }

    /**
     * @testdox CTI.5 getMissedDueIfRecoverable retrieves data from Redis
     */
    public function testGetMissedDueIfRecoverableRetrievesDataFromRedis(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();
        $event = $this->createEvent('php artisan test:redis-missed', $clock);
        $event->cron('0 * * * *'); // every hour at :00

        // Recorded execution at 10:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // Check at 11:05 (11:00 was missed)
        $now = new DateTimeImmutable('2024-01-15 11:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNotNull($result);
        $this->assertSame('11', $result->format('H'));
        $this->assertSame('00', $result->format('i'));
    }

    /**
     * @testdox CTI.6 Concurrent lock acquisition across separate trackers fails
     */
    public function testConcurrentLockAcquisitionAcrossSeparateTrackersFails(): void
    {
        $tracker1 = $this->createTracker();
        $cache2 = $this->createRedisCache();
        $tracker2 = new CacheExecutionTracker($cache2, $cache2->getStore(), $this->logger);
        $event = $this->createEvent('php artisan test:concurrent');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $result1 = $tracker1->acquireLock($event, $dueAt);
        $result2 = $tracker2->acquireLock($event, $dueAt);

        $this->assertTrue($result1);
        $this->assertFalse($result2);
    }

    /**
     * @testdox CTI.7 Lock expires after TTL allowing re-acquisition
     * @group slow
     */
    public function testLockExpiresAfterTtlAllowingReAcquisition(): void
    {
        $shortTtl = 1; // 1 second
        $tracker1 = $this->createTracker($shortTtl);
        $event = $this->createEvent('php artisan test:ttl-expiry');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $tracker1->acquireLock($event, $dueAt);

        // Poll until TTL expires (reduces wasted wait compared to fixed sleep)
        $cache2 = $this->createRedisCache();
        $tracker2 = new CacheExecutionTracker($cache2, $cache2->getStore(), $this->logger);
        $deadline = microtime(true) + 3.0;
        $result = false;
        while (microtime(true) < $deadline) {
            usleep(50000); // 50ms 間隔
            if ($tracker2->acquireLock($event, $dueAt)) {
                $result = true;
                break;
            }
        }

        $this->assertTrue($result);
    }
}
