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
        $logMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message, $context) use (&$logMessages) {
            $logMessages[] = ['message' => $message, 'context' => $context];
        });

        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 14:35:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $logger);
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
        $event->cron('0 * * * *'); // every hour at :00
        $event->withGracePeriod(30); // 30 minutes

        // Recorded execution at 10:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // Check at 14:35 → missedDue=14:00, deadline=14:00+30min=14:30, now(14:35)>deadline
        $now = new DateTimeImmutable('2024-01-15 14:35:00');
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
     * @testdox CT.13 markExecuted uses grace period for TTL calculation with ClockAwareEvent
     */
    public function testMarkExecutedUsesGracePeriodForTtl(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 10:00:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
        $event->withGracePeriod(120); // 2 hours = 7200 seconds

        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $tracker->markExecuted($event, $dueAt);

        // Verify the key was stored
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
        $event->cron('0 * * * *'); // every hour at :00

        // Recorded execution at 10:00
        $tracker->markExecuted($event, new DateTimeImmutable('2024-01-15 10:00:00'));

        // Check at 14:05 (plain Event has no grace period check)
        $now = new DateTimeImmutable('2024-01-15 14:05:00');
        $result = $tracker->getMissedDueIfRecoverable($event, $now);

        // Plain Event has no grace period check, so missedDue is returned
        $this->assertNotNull($result);
    }

    /**
     * @testdox CT.15 getMissedDueIfRecoverable returns missedDue when ClockAwareEvent has no gracePeriod set
     */
    public function testGetMissedDueIfRecoverableReturnsMissedDueWhenNoGracePeriod(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 14:05:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);

        // Create ClockAwareEvent without calling withGracePeriod()
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
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
     * @testdox CT.16 Grace period deadline is calculated from missedDue, not lastExecutedDue
     */
    public function testGracePeriodDeadlineUsedMissedDueNotLastExecutedDue(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 03:15:00'));
        $tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
        $event = $this->createClockAwareEvent('php artisan test:task', $clock);
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
     * @testdox CT.17 dateIntervalToSeconds handles days from DateInterval constructor
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
}
