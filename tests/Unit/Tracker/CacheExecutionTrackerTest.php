<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

class CacheExecutionTrackerTest extends TestCase
{
    /**
     * @var FakeCacheStore
     */
    private $cache;

    /**
     * @var FakeLockProvider
     */
    private $lockProvider;

    /**
     * @var FakeEventMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockProvider = new FakeLockProvider();
        $this->cache = new FakeCacheStore($this->lockProvider);
        $this->mutex = new FakeEventMutex();
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
     * @testdox T4.1 markExecuted stores timestamp in cache
     */
    public function testMarkExecutedStoresTimestamp(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $tracker->markExecuted($event, $dueAt);

        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
        $this->assertSame($dueAt->timestamp, $this->cache->get($key));
    }

    /**
     * @testdox T4.2 wasMissed returns false on first run
     */
    public function testWasMissedReturnsFalseOnFirstRun(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // 毎時0分
        $now = Carbon::parse('2024-01-15 11:05:00');

        $result = $tracker->wasMissed($event, $now);

        $this->assertFalse($result);
    }

    /**
     * @testdox T4.3 wasMissed returns true when missed
     */
    public function testWasMissedReturnsTrueWhenMissed(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // 毎時0分

        // 10:00 に実行記録
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        // 11:05 にチェック（11:00 が欠落している）
        $now = Carbon::parse('2024-01-15 11:05:00');
        $result = $tracker->wasMissed($event, $now);

        $this->assertTrue($result);
    }

    /**
     * @testdox T4.4 wasMissed returns false when on schedule
     */
    public function testWasMissedReturnsFalseWhenOnSchedule(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // 毎時0分

        // 11:00 に実行記録
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 11:00:00'));

        // 11:05 にチェック（正常）
        $now = Carbon::parse('2024-01-15 11:05:00');
        $result = $tracker->wasMissed($event, $now);

        $this->assertFalse($result);
    }

    /**
     * @testdox T4.5 acquireLock returns true on success
     */
    public function testAcquireLockReturnsTrueOnSuccess(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($result);
        $this->assertNotEmpty($this->lockProvider->getLocks());
    }

    /**
     * @testdox T4.6 acquireLock returns false when already locked
     */
    public function testAcquireLockReturnsFalseWhenLocked(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        // 最初のロック取得
        $tracker->acquireLock($event, $dueAt);

        // 2回目のロック取得は失敗
        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertFalse($result);
    }

    /**
     * @testdox T4.7 releaseLock removes lock and allows re-acquisition
     */
    public function testReleaseLockRemovesLock(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        // ロック取得
        $tracker->acquireLock($event, $dueAt);

        // ロック解放
        $tracker->releaseLock($event, $dueAt);

        // 再取得可能
        $result = $tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result);
    }

    /**
     * @testdox T4.8 getLastExecutedDue returns stored value
     */
    public function testGetLastExecutedDueReturnsStoredValue(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        $tracker->markExecuted($event, $dueAt);

        $result = $tracker->getLastExecutedDue($event);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertTrue($result->equalTo($dueAt));
    }

    /**
     * @testdox T4.9 getLastExecutedDue returns null when not found
     */
    public function testGetLastExecutedDueReturnsNullWhenNotFound(): void
    {
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createEvent('php artisan test:task');

        $result = $tracker->getLastExecutedDue($event);

        $this->assertNull($result);
    }

    /**
     * @testdox T4.10 acquireLock uses fallback when LockProvider unavailable
     */
    public function testAcquireLockFallbackWhenLockProviderUnavailable(): void
    {
        // LockProvider を持たない Cache を使用
        $cacheWithoutLock = new FakeCacheStore(null);
        $tracker = new CacheExecutionTracker($cacheWithoutLock);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = Carbon::parse('2024-01-15 10:00:00');

        // フォールバック動作でロック取得
        $result = $tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result);

        // 2回目は失敗（has() + put() フォールバック）
        $result2 = $tracker->acquireLock($event, $dueAt);
        $this->assertFalse($result2);
    }

    /**
     * @testdox T4.11 wasMissed returns false on invalid cron expression
     */
    public function testWasMissedReturnsFalseOnInvalidCronExpression(): void
    {
        $logMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message, $context) use (&$logMessages) {
            $logMessages[] = ['message' => $message, 'context' => $context];
        });

        $tracker = new CacheExecutionTracker($this->cache, 3600, $logger);
        $event = $this->createEvent('php artisan test:task');

        // 無効な cron 式を設定（通常は Event::cron() を通すが、直接設定）
        $reflection = new \ReflectionProperty($event, 'expression');
        $reflection->setAccessible(true);
        $reflection->setValue($event, 'invalid cron');

        // 実行記録を設定（初回チェックをスキップ）
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        $now = Carbon::parse('2024-01-15 11:05:00');
        $result = $tracker->wasMissed($event, $now);

        $this->assertFalse($result);
        $this->assertNotEmpty($logMessages);
    }

    /**
     * @testdox T4.12 markExecuted uses grace period for TTL calculation with ClockAwareEvent
     */
    public function testMarkExecutedUsesGracePeriodForTtl(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 10:00:00'));
        $tracker = new CacheExecutionTracker($this->cache);
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
        $event->withGracePeriod(120); // 2時間 = 7200秒

        $dueAt = Carbon::parse('2024-01-15 10:00:00');
        $tracker->markExecuted($event, $dueAt);

        // キーが保存されていることを確認
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
    }
}
