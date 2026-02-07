<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\AdvancingClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RuntimeException;

class FreezableClockTest extends TestCase
{
    /**
     * @testdox T1.5
     */
    public function testDelegatesToInnerClockWhenNotFrozen(): void
    {
        $fixedTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $inner = new FixedClock($fixedTime);
        $clock = new FreezableClock($inner);

        $this->assertEquals($fixedTime, $clock->now());
    }

    /**
     * @testdox T1.6
     */
    public function testReturnsFrozenTimeWithinCallback(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $inner = new FixedClock($innerTime);
        $clock = new FreezableClock($inner);

        $clock->withFrozenTime($frozenTime, function () use ($clock, $frozenTime) {
            $this->assertEquals($frozenTime, $clock->now());
        });
    }

    /**
     * @testdox T1.7
     */
    public function testWithFrozenTimeReturnsCallbackResult(): void
    {
        $inner = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $clock = new FreezableClock($inner);
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');

        $result = $clock->withFrozenTime($frozenTime, function () {
            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    /**
     * @testdox T1.8
     */
    public function testUnfreezesAfterCallback(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $inner = new FixedClock($innerTime);
        $clock = new FreezableClock($inner);

        $clock->withFrozenTime($frozenTime, function () {
            // no-op
        });

        $this->assertEquals($innerTime, $clock->now());
    }

    /**
     * @testdox T1.9
     */
    public function testUnfreezesEvenIfCallbackThrows(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $inner = new FixedClock($innerTime);
        $clock = new FreezableClock($inner);

        try {
            $clock->withFrozenTime($frozenTime, function () {
                throw new RuntimeException('test error');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertEquals($innerTime, $clock->now());
    }

    /**
     * @testdox T1.10
     */
    public function testFrozenTimeStaysConstantAcrossMultipleCalls(): void
    {
        $start = new DateTimeImmutable('2024-01-15 12:00:00');
        $inner = new AdvancingClock($start, 1);
        $clock = new FreezableClock($inner);

        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');

        $clock->withFrozenTime($frozenTime, function () use ($clock, $frozenTime) {
            $first = $clock->now();
            $second = $clock->now();
            $third = $clock->now();

            $this->assertEquals($frozenTime, $first);
            $this->assertEquals($frozenTime, $second);
            $this->assertEquals($frozenTime, $third);
        });
    }
}
