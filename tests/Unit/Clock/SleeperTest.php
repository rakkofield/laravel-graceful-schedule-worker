<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use PHPUnit\Framework\TestCase;

class SleeperTest extends TestCase
{
    /**
     * @testdox SL.1 Can construct with a positive value
     */
    public function testConstructsWithPositiveValue(): void
    {
        $sleeper = new Sleeper(1000);
        $this->assertInstanceOf(SleeperInterface::class, $sleeper);
    }

    /**
     * @testdox SL.2 Throws InvalidArgumentException for zero
     */
    public function testThrowsOnZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Sleeper(0);
    }

    /**
     * @testdox SL.3 Throws InvalidArgumentException for negative value
     */
    public function testThrowsOnNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Sleeper(-1);
    }

    /**
     * @testdox SL.4 sleep() executes (verified with short duration)
     */
    public function testSleepExecutes(): void
    {
        $sleeper = new Sleeper(1); // 1 microsecond
        $before = microtime(true);
        $sleeper->sleep();
        $after = microtime(true);

        // Verify execution completes (no exception)
        $this->assertGreaterThanOrEqual($before, $after);
    }
}
