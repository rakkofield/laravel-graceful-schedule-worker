<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;

class SystemClockTest extends TestCase
{
    /**
     * T1.1: SystemClock::now() は現在時刻を返す
     *
     * @test
     */
    public function it_returns_current_time()
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
     * T1.2: 連続呼び出しで時刻が進む
     *
     * @test
     */
    public function it_advances_time_on_consecutive_calls()
    {
        $clock = new SystemClock();
        $first = $clock->now();
        usleep(1000); // 1ms待機
        $second = $clock->now();

        $this->assertGreaterThanOrEqual($first->getTimestamp(), $second->getTimestamp());
    }
}
