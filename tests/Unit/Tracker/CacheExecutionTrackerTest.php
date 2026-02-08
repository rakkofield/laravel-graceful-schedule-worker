<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;

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

    /**
     * @var NullLogger
     */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockProvider = new FakeLockProvider();
        $this->cache = new FakeCacheStore($this->lockProvider);
        $this->mutex = new FakeEventMutex();
        $this->logger = new NullLogger();
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
     * @testdox CT.1 markExecuted stores timestamp in cache
     */
    public function testMarkExecutedStoresTimestamp(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $tracker->markExecuted($event, $dueAt);

        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
        $this->assertSame($dueAt->getTimestamp(), $this->cache->get($key));
    }

    /**
     * @testdox CT.2 constructor throws exception for non-positive lockTtl
     */
    public function testConstructorThrowsExceptionForNonPositiveLockTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lockTtl must be a positive integer');

        new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger, 0);
    }

    /**
     * @testdox CT.3 constructor throws exception for negative lockTtl
     */
    public function testConstructorThrowsExceptionForNegativeLockTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lockTtl must be a positive integer');

        new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger, -1);
    }

    /**
     * @testdox CT.4 getMissedDueIfRecoverable returns null on first run
     */
    public function testGetMissedDueIfRecoverableReturnsNullOnFirstRun(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // 毎時0分
        $now = new DateTimeImmutable('2024-01-15 11:05:00');

        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
    }

    /**
     * @testdox CT.5 getMissedDueIfRecoverable returns missedDue when missed
     */
    public function testGetMissedDueIfRecoverableReturnsMissedDue(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // 毎時0分

        // 10:00 に実行記録
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // 11:05 にチェック（11:00 が欠落している）
        $now = new DateTimeImmutable('2024-01-15 11:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNotNull($result);
        $this->assertSame('11', $result->format('H'));
        $this->assertSame('00', $result->format('i'));
    }

    /**
     * @testdox CT.6 getMissedDueIfRecoverable returns null when on schedule
     */
    public function testGetMissedDueIfRecoverableReturnsNullWhenOnSchedule(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // 毎時0分

        // 11:00 に実行記録
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 11:00:00'));

        // 11:05 にチェック（正常）
        $now = new DateTimeImmutable('2024-01-15 11:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
    }

    /**
     * @testdox CT.7 getMissedDueIfRecoverable returns null when grace period exceeded
     */
    public function testGetMissedDueIfRecoverableReturnsNullWhenGracePeriodExceeded(): void
    {
        $logMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message, $context) use (&$logMessages) {
            $logMessages[] = ['message' => $message, 'context' => $context];
        });

        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 14:05:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $logger);
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
        $event->cron('0 * * * *'); // 毎時0分
        $event->withGracePeriod(120); // 2時間

        // 10:00 に実行記録（14:05 では grace period 超過）
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // 14:05 にチェック
        $now = new DateTimeImmutable('2024-01-15 14:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
        $this->assertNotEmpty($logMessages);
        $this->assertStringContainsString('grace period exceeded', $logMessages[0]['message']);
    }

    /**
     * @testdox CT.8 getMissedDueIfRecoverable returns missedDue within grace period
     */
    public function testGetMissedDueIfRecoverableReturnsMissedDueWithinGracePeriod(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 11:30:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
        $event->cron('0 * * * *'); // 毎時0分
        $event->withGracePeriod(120); // 2時間

        // 10:00 に実行記録（11:30 は grace period 内）
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // 11:30 にチェック
        $now = new DateTimeImmutable('2024-01-15 11:30:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNotNull($result);
        $this->assertSame('11', $result->format('H'));
    }

    /**
     * @testdox CT.9 getMissedDueIfRecoverable throws on invalid cron expression
     */
    public function testGetMissedDueIfRecoverableThrowsOnInvalidCron(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');

        // 無効な cron 式を設定
        $reflection = new \ReflectionProperty($event, 'expression');
        $reflection->setAccessible(true);
        $reflection->setValue($event, 'invalid cron');

        // 実行記録を設定（初回チェックをスキップ）
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cron expression');

        $now = new DateTimeImmutable('2024-01-15 11:05:00');
        $tracker->getMissedDueIfRecoverable($event, $now);
    }

    /**
     * @testdox CT.10 acquireLock returns true on success
     */
    public function testAcquireLockReturnsTrueOnSuccess(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertTrue($result);
        $this->assertNotEmpty($this->lockProvider->getLocks());
    }

    /**
     * @testdox CT.11 acquireLock returns false when already locked
     */
    public function testAcquireLockReturnsFalseWhenLocked(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // 最初のロック取得
        $tracker->acquireLock($event, $dueAt);

        // 2回目のロック取得は失敗
        $result = $tracker->acquireLock($event, $dueAt);

        $this->assertFalse($result);
    }

    /**
     * @testdox CT.12 releaseLock removes lock and allows re-acquisition
     */
    public function testReleaseLockRemovesLock(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // ロック取得
        $tracker->acquireLock($event, $dueAt);

        // ロック解放
        $tracker->releaseLock($event, $dueAt);

        // 再取得可能
        $result = $tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result);
    }

    /**
     * @testdox CT.13 markExecuted uses grace period for TTL calculation with ClockAwareEvent
     */
    public function testMarkExecutedUsesGracePeriodForTtl(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 10:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
        $event->withGracePeriod(120); // 2時間 = 7200秒

        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $tracker->markExecuted($event, $dueAt);

        // キーが保存されていることを確認
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
    }

    /**
     * @testdox CT.14 getMissedDueIfRecoverable works with non-ClockAwareEvent (no grace period check)
     */
    public function testGetMissedDueIfRecoverableWorksWithNonClockAwareEvent(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // 毎時0分

        // 10:00 に実行記録
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // 14:05 にチェック（通常の Event なので grace period チェックなし）
        $now = new DateTimeImmutable('2024-01-15 14:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        // 通常の Event は grace period チェックがないので missedDue が返る
        $this->assertNotNull($result);
    }

    /**
     * @testdox CT.15 getMissedDueIfRecoverable returns missedDue when ClockAwareEvent has no gracePeriod set
     */
    public function testGetMissedDueIfRecoverableReturnsMissedDueWhenNoGracePeriod(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 14:05:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // ClockAwareEvent を作成するが withGracePeriod() を呼ばない
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
        $event->cron('0 * * * *'); // 毎時0分

        // 10:00 に実行記録
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // 14:05 にチェック（grace period 未設定なので、時間経過に関係なく missedDue が返る）
        $now = new DateTimeImmutable('2024-01-15 14:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        // gracePeriod が null の ClockAwareEvent は grace period チェックをスキップ
        $this->assertNotNull($result);
        $this->assertSame('14', $result->format('H'));
    }
}
