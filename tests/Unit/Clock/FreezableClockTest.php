<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FreezableClockTest extends TestCase
{
    /**
     * @testdox FC.1 Delegates to inner Clock when not frozen
     */
    public function testDelegatesToInnerClockWhenNotFrozen(): void
    {
        $fixedTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $inner = new FixedClock($fixedTime);
        $clock = new FreezableClock($inner);

        $this->assertEquals($fixedTime, $clock->now());
    }

    /**
     * @testdox FC.2 Returns frozen time within withFrozenTime callback
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
     * @testdox FC.3 withFrozenTime returns the callback's return value
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
     * @testdox FC.4 Unfreezes after callback completes
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
     * @testdox FC.5 Unfreezes even if callback throws an exception
     */
    public function testUnfreezesEvenIfCallbackThrows(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $inner = new FixedClock($innerTime);
        $clock = new FreezableClock($inner);

        $exceptionThrown = false;
        try {
            $clock->withFrozenTime($frozenTime, function () {
                throw new RuntimeException('test error');
            });
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown, 'Expected RuntimeException to be propagated');
        $this->assertEquals($innerTime, $clock->now());
    }

    /**
     * @testdox FC.6 Returns the same time across multiple calls while frozen
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

    /**
     * @testdox FC.7 Nested withFrozenTime throws LogicException
     */
    public function testThrowsOnNestedWithFrozenTime(): void
    {
        $inner = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $clock = new FreezableClock($inner);

        $outerTime = new DateTimeImmutable('2024-01-15 10:00:00');
        $innerTime = new DateTimeImmutable('2024-01-15 11:00:00');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FreezableClock::withFrozenTime() cannot be nested.');

        $clock->withFrozenTime($outerTime, function () use ($clock, $innerTime) {
            $clock->withFrozenTime($innerTime, function () {
                // should not reach here
            });
        });
    }
}
