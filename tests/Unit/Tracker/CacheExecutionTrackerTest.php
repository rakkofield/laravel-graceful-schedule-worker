<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;

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
     * @param ClockInterface|null $clock
     * @return ClockAwareEvent
     */
    private function createEvent(string $command, ClockInterface $clock = null): ClockAwareEvent
    {
        $defaultClock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent(
            $this->mutex,
            $command,
            $clock ?? $defaultClock,
            'local',
            null,
            new TimezoneResolver()
        );
    }

    /**
     * @param string $command
     * @param ClockInterface|null $clock
     * @param \DateTimeZone|string|null $timezone
     * @return ClockAwareEvent
     */
    private function createEventWithTimezone(
        string $command,
        ClockInterface $clock = null,
        $timezone = null
    ): ClockAwareEvent {
        $defaultClock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent(
            $this->mutex,
            $command,
            $clock ?? $defaultClock,
            'local',
            $timezone,
            new TimezoneResolver()
        );
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
        $event->cron('0 * * * *'); // every hour at :00
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
     * @testdox CT.6 getMissedDueIfRecoverable returns null when on schedule
     */
    public function testGetMissedDueIfRecoverableReturnsNullWhenOnSchedule(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $event->cron('0 * * * *'); // every hour at :00

        // Recorded execution at 11:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 11:00:00'));

        // Check at 11:05 (on schedule)
        $now = new DateTimeImmutable('2024-01-15 11:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
    }

    /**
     * @testdox CT.7 getMissedDueIfRecoverable returns null when grace period exceeded
     */
    public function testGetMissedDueIfRecoverableReturnsNullWhenGracePeriodExceeded(): void
    {
        $logger = new SpyLogger();

        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 14:35:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $logger);
        $event = $this->createEvent('php artisan test:task', $clock);
        $event->cron('0 * * * *'); // every hour at :00
        $event->withGracePeriod(30); // 30 minutes

        // Recorded execution at 10:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // Check at 14:35 → missedDue=14:00, deadline=14:00+30min=14:30, now(14:35)>deadline
        $now = new DateTimeImmutable('2024-01-15 14:35:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
        $this->assertTrue($logger->hasLogContaining('warning', 'grace period exceeded'));
    }

    /**
     * @testdox CT.8 getMissedDueIfRecoverable returns missedDue within grace period
     */
    public function testGetMissedDueIfRecoverableReturnsMissedDueWithinGracePeriod(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 11:30:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task', $clock);
        $event->cron('0 * * * *'); // every hour at :00
        $event->withGracePeriod(120); // 2 hours

        // Recorded execution at 10:00 (11:30 is within grace period)
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // Check at 11:30
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

        // Set an invalid cron expression
        $reflection = new \ReflectionProperty($event, 'expression');
        $reflection->setAccessible(true);
        $reflection->setValue($event, 'invalid cron');

        // Set execution record (to skip the first-run check)
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

        // First lock acquisition
        $tracker->acquireLock($event, $dueAt);

        // Second lock acquisition fails
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

        // Acquire lock
        $tracker->acquireLock($event, $dueAt);

        // Release lock
        $tracker->releaseLock($event, $dueAt);

        // Re-acquisition succeeds
        $result = $tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result);
    }

    /**
     * @testdox CT.13 markExecuted uses grace period for TTL calculation
     */
    public function testMarkExecutedUsesGracePeriodForTtl(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 10:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task', $clock);
        $event->withGracePeriod(120); // 2 hours = 7200 seconds

        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $tracker->markExecuted($event, $dueAt);

        // Verify the key was stored
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
    }

    /**
     * @testdox CT.14 getMissedDueIfRecoverable returns missedDue when no gracePeriod set
     */
    public function testGetMissedDueIfRecoverableReturnsMissedDueWhenNoGracePeriod(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 14:05:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // Create ClockAwareEvent without calling withGracePeriod()
        $event = $this->createEvent('php artisan test:task', $clock);
        $event->cron('0 * * * *'); // every hour at :00

        // Recorded execution at 10:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // Check at 14:05 (no grace period set, so missedDue is returned regardless of elapsed time)
        $now = new DateTimeImmutable('2024-01-15 14:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        // ClockAwareEvent with null gracePeriod skips the grace period check
        $this->assertNotNull($result);
        $this->assertSame('14', $result->format('H'));
    }

    /**
     * @testdox CT.15 Grace period deadline is calculated from missedDue, not lastExecutedDue
     */
    public function testGracePeriodDeadlineUsedMissedDueNotLastExecutedDue(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 03:15:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task', $clock);
        $event->cron('0 3 * * *'); // daily at 03:00
        $event->withGracePeriod(30); // 30 minutes

        // Recorded execution at yesterday 03:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-14 03:00:00'));

        // Check at today 03:15 (missedDue = today 03:00, within 30min grace period from missedDue)
        $now = new DateTimeImmutable('2024-01-15 03:15:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        // Correct: deadline = missedDue(2024-01-15 03:00) + 30min = 03:30 → now(03:15) < deadline → recoverable
        // Bug:     deadline = lastExecutedDue(2024-01-14 03:00) + 30min = yesterday 03:30 → now > deadline → null
        $this->assertNotNull($result);
        $this->assertSame('2024-01-15', $result->format('Y-m-d'));
        $this->assertSame('03', $result->format('H'));
        $this->assertSame('00', $result->format('i'));
    }

    /**
     * @testdox CT.16 dateIntervalToSeconds handles days from DateInterval constructor
     */
    public function testDateIntervalToSecondsHandlesDaysFromConstructor(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        $reflection = new \ReflectionMethod($tracker, 'dateIntervalToSeconds');
        $reflection->setAccessible(true);

        // new DateInterval('P1D') sets $d = 1 but $days = false
        $interval = new \DateInterval('P1D');
        $result = $reflection->invoke($tracker, $interval);

        // Should be 86400 seconds (1 day)
        // Bug: falls back to 0 instead of $interval->d, returning 0
        $this->assertSame(86400, $result);
    }

    /**
     * @testdox CT.17 calculateTtl enforces minimum of DEFAULT_TTL_SECONDS for short grace periods
     */
    public function testCalculateTtlEnforcesMinimumForShortGracePeriod(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 10:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task', $clock);
        $event->withGracePeriod(5); // 5 minutes = 300 seconds → 2x = 600 < 86400

        $reflection = new \ReflectionMethod($tracker, 'calculateTtl');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($tracker, $event);

        // Should be DEFAULT_TTL_SECONDS (86400) since 300*2=600 < 86400
        $this->assertSame(86400, $result);
    }

    /**
     * @testdox CT.18 calculateTtl uses doubled grace period when it exceeds DEFAULT_TTL_SECONDS
     */
    public function testCalculateTtlUsesDoubledGracePeriodWhenExceedsDefault(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 10:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task', $clock);
        $event->withGracePeriod(1440); // 24 hours = 86400 seconds → 2x = 172800 > 86400

        $reflection = new \ReflectionMethod($tracker, 'calculateTtl');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($tracker, $event);

        // Should be 172800 (86400 * 2) since it exceeds DEFAULT_TTL_SECONDS
        $this->assertSame(172800, $result);
    }

    /**
     * @testdox CT.19 getMissedDueIfRecoverable evaluates cron expression in event timezone
     */
    public function testGetMissedDueIfRecoverableEvaluatesCronInEventTimezone(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // Event scheduled at 09:00 Asia/Tokyo (= 00:00 UTC)
        $event = $this->createEventWithTimezone('php artisan test:task', null, 'Asia/Tokyo');
        $event->cron('0 9 * * *'); // 09:00 in Asia/Tokyo

        // Mark yesterday's 09:00 JST as executed (= 2024-01-14 00:00 UTC)
        $yesterdayDue = new DateTimeImmutable('2024-01-14 00:00:00', new \DateTimeZone('UTC'));
        $tracker->markExecuted($event, $yesterdayDue);

        // Now is 2024-01-15 01:00 UTC (= 2024-01-15 10:00 JST), so today's 09:00 JST was missed
        $now = new DateTimeImmutable('2024-01-15 01:00:00', new \DateTimeZone('UTC'));
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        // Should detect 09:00 JST (= 00:00 UTC on 2024-01-15) as missed
        $this->assertNotNull($result);
        $this->assertSame('2024-01-15', $result->format('Y-m-d'));
    }

    /**
     * @testdox CT.20 getMissedDueIfRecoverable does not false-positive when timezone shifts cron evaluation
     */
    public function testGetMissedDueIfRecoverableNoFalsePositiveWithTimezoneShift(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // Event scheduled at 09:00 Asia/Tokyo
        $event = $this->createEventWithTimezone('php artisan test:task', null, 'Asia/Tokyo');
        $event->cron('0 9 * * *');

        // Mark today's 09:00 JST as already executed (= 2024-01-15 00:00 UTC)
        $todayDue = new DateTimeImmutable('2024-01-15 00:00:00', new \DateTimeZone('UTC'));
        $tracker->markExecuted($event, $todayDue);

        // Now is 2024-01-15 01:00 UTC (= 10:00 JST), today's run was already executed
        $now = new DateTimeImmutable('2024-01-15 01:00:00', new \DateTimeZone('UTC'));
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
    }

    /**
     * @testdox CT.21 getMissedDueIfRecoverable handles DateTimeZone object as event timezone
     */
    public function testGetMissedDueIfRecoverableHandlesDateTimeZoneObject(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // Use DateTimeZone object instead of string
        $event = $this->createEventWithTimezone('php artisan test:task', null, new \DateTimeZone('Asia/Tokyo'));
        $event->cron('0 9 * * *');

        // Mark yesterday's 09:00 JST as executed
        $yesterdayDue = new DateTimeImmutable('2024-01-14 00:00:00', new \DateTimeZone('UTC'));
        $tracker->markExecuted($event, $yesterdayDue);

        // Now is 2024-01-15 01:00 UTC (= 10:00 JST)
        $now = new DateTimeImmutable('2024-01-15 01:00:00', new \DateTimeZone('UTC'));
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNotNull($result);
        $this->assertSame('2024-01-15', $result->format('Y-m-d'));
    }

    /**
     * @testdox CT.22 releaseLock releases lock so it can be re-acquired
     */
    public function testReleaseLockWorksCorrectlyAfterImplementationChange(): void
    {
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // Acquire lock
        $this->assertTrue($tracker->acquireLock($event, $dueAt));

        // Second acquire fails (lock held)
        $this->assertFalse($tracker->acquireLock($event, $dueAt));

        // Release lock
        $tracker->releaseLock($event, $dueAt);

        // Re-acquire succeeds (lock was actually released)
        $this->assertTrue($tracker->acquireLock($event, $dueAt));

        // And second acquire fails again
        $this->assertFalse($tracker->acquireLock($event, $dueAt));
    }
}
