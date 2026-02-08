<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SystemClockTest extends TestCase
{
    /**
     * @testdox SC.1 now() が現在時刻の DateTimeImmutable を返す
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
     * @testdox SC.2 連続呼び出しで時刻が進む
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
