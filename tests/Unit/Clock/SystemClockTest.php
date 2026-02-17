<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SystemClockTest extends TestCase
{
    /**
     * @testdox SC.1 now() returns a DateTimeImmutable with the current time
     */
    public function testReturnsCurrentTime(): void
    {
        $clock = new SystemClock();
        $before = new DateTimeImmutable();
        $now = $clock->now();
        $after = new DateTimeImmutable();

        $this->assertInstanceOf(DateTimeImmutable::class, $now);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }

    /**
     * @testdox SC.2 Time advances on consecutive calls
     */
    public function testAdvancesTimeOnConsecutiveCalls(): void
    {
        $clock = new SystemClock();
        $first = $clock->now();
        usleep(1000); // wait 1ms
        $second = $clock->now();

        $this->assertGreaterThanOrEqual($first->getTimestamp(), $second->getTimestamp());
    }
}
