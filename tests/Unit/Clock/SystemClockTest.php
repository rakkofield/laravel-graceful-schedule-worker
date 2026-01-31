<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;

class SystemClockTest extends TestCase
{
    /**
     * @testdox T1.1
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
     * @testdox T1.2
     */
    public function testAdvancesTimeOnConsecutiveCalls(): void
    {
        $clock = new SystemClock();
        $first = $clock->now();
        usleep(1000); // 1ms待機
        $second = $clock->now();

        $this->assertGreaterThanOrEqual($first->getTimestamp(), $second->getTimestamp());
    }
}
