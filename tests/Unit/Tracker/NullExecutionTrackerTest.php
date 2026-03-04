<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

class NullExecutionTrackerTest extends TestCase
{
    /** @var FakeEventMutex */
    private $mutex;

    /** @var NullExecutionTracker */
    private $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutex = new FakeEventMutex();
        $this->tracker = new NullExecutionTracker();
    }

    /**
     * @param string $command
     * @return ClockAwareEvent
     */
    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock, 'local', null, new TimezoneResolver());
    }

    /**
     * @testdox NT.1 markExecuted does nothing (no exception)
     */
    public function testMarkExecutedDoesNothing(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // Passes if no exception is thrown
        $this->tracker->markExecuted($event, $dueAt);
        $this->assertTrue(true);
    }

    /**
     * @testdox NT.2 getMissedDueIfRecoverable always returns null
     */
    public function testGetMissedDueIfRecoverableAlwaysReturnsNull(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $now = new DateTimeImmutable('2024-01-15 11:00:00');

        $result = $this->tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
    }

    /**
     * @testdox NT.3 acquireLock always returns true
     */
    public function testAcquireLockAlwaysReturnsTrue(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // First call
        $result1 = $this->tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result1);

        // Second call also succeeds (NullObject, so no lock contention)
        $result2 = $this->tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result2);
    }

    /**
     * @testdox NT.4 releaseLock does nothing (no exception)
     */
    public function testReleaseLockDoesNothing(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // Passes if no exception is thrown
        $this->tracker->releaseLock($event, $dueAt);
        $this->assertTrue(true);
    }
}
