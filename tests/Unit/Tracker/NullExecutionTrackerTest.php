<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;

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
     * @return Event
     */
    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    /**
     * @testdox T6.1 markExecuted does nothing (no exception)
     */
    public function testMarkExecutedDoesNothing(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // 例外なく完了すればOK
        $this->tracker->markExecuted($event, $dueAt);
        $this->assertTrue(true);
    }

    /**
     * @testdox T6.2 getMissedDueIfRecoverable always returns null
     */
    public function testGetMissedDueIfRecoverableAlwaysReturnsNull(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $now = new DateTimeImmutable('2024-01-15 11:00:00');

        $result = $this->tracker->getMissedDueIfRecoverable($event, $now);

        $this->assertNull($result);
    }

    /**
     * @testdox T6.3 acquireLock always returns true
     */
    public function testAcquireLockAlwaysReturnsTrue(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // 1回目
        $result1 = $this->tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result1);

        // 2回目も成功（NullObject なのでロック競合しない）
        $result2 = $this->tracker->acquireLock($event, $dueAt);
        $this->assertTrue($result2);
    }

    /**
     * @testdox T6.4 releaseLock does nothing (no exception)
     */
    public function testReleaseLockDoesNothing(): void
    {
        $event = $this->createEvent('php artisan test:task');
        $dueAt = new DateTimeImmutable('2024-01-15 10:00:00');

        // 例外なく完了すればOK
        $this->tracker->releaseLock($event, $dueAt);
        $this->assertTrue(true);
    }
}
