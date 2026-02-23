<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;

class ClockAwareTimeFilterTest extends TestCase
{
    /**
     * @testdox TF.1 nowWithTimezone returns clock time without timezone when null
     */
    public function testNowWithTimezoneReturnsClockTimeWhenNoTimezone(): void
    {
        $now = new DateTimeImmutable('2024-01-15 12:00:00', new DateTimeZone('UTC'));
        $clock = new FixedClock($now);
        $filter = new ClockAwareTimeFilter($clock, null);

        $result = $filter->nowWithTimezone();

        $this->assertEquals($now->getTimestamp(), $result->getTimestamp());
    }

    /**
     * @testdox TF.2 nowWithTimezone applies timezone conversion
     */
    public function testNowWithTimezoneAppliesTimezoneConversion(): void
    {
        // UTC 03:00 = Asia/Tokyo 12:00
        $now = new DateTimeImmutable('2024-01-15 03:00:00', new DateTimeZone('UTC'));
        $clock = new FixedClock($now);
        $filter = new ClockAwareTimeFilter($clock, new DateTimeZone('Asia/Tokyo'));

        $result = $filter->nowWithTimezone();

        $this->assertSame('12', $result->format('H'));
        $this->assertSame('Asia/Tokyo', $result->getTimezone()->getName());
    }

    /**
     * @testdox TF.3 createInterval returns true when time is within range
     */
    public function testCreateIntervalReturnsTrueWithinRange(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $filter = new ClockAwareTimeFilter($clock, null);

        $closure = $filter->createInterval('09:00', '17:00');

        $this->assertTrue($closure());
    }

    /**
     * @testdox TF.4 createInterval returns false when time is outside range
     */
    public function testCreateIntervalReturnsFalseOutsideRange(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 20:00:00'));
        $filter = new ClockAwareTimeFilter($clock, null);

        $closure = $filter->createInterval('09:00', '17:00');

        $this->assertFalse($closure());
    }

    /**
     * @testdox TF.5 createInterval handles midnight crossing with now after midnight
     */
    public function testCreateIntervalHandlesMidnightCrossingNowAfterMidnight(): void
    {
        // 00:30, between 23:00-01:00
        $clock = new FixedClock(new DateTimeImmutable('2024-01-02 00:30:00'));
        $filter = new ClockAwareTimeFilter($clock, null);

        $closure = $filter->createInterval('23:00', '01:00');

        $this->assertTrue($closure());
    }

    /**
     * @testdox TF.6 createInterval handles midnight crossing with now before midnight
     */
    public function testCreateIntervalHandlesMidnightCrossingNowBeforeMidnight(): void
    {
        // 23:30, between 23:00-01:00
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 23:30:00'));
        $filter = new ClockAwareTimeFilter($clock, null);

        $closure = $filter->createInterval('23:00', '01:00');

        $this->assertTrue($closure());
    }

    /**
     * @testdox TF.7 createInterval with timezone converts time correctly
     */
    public function testCreateIntervalWithTimezoneConvertsCorrectly(): void
    {
        // UTC 03:00 = Asia/Tokyo 12:00
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 03:00:00', new DateTimeZone('UTC')));
        $filter = new ClockAwareTimeFilter($clock, new DateTimeZone('Asia/Tokyo'));

        // 11:00-13:00 Tokyo time — 12:00 is within range
        $closure = $filter->createInterval('11:00', '13:00');

        $this->assertTrue($closure());
    }

    /**
     * @testdox TF.8 createInterval with timezone returns false when out of range
     */
    public function testCreateIntervalWithTimezoneReturnsFalseOutOfRange(): void
    {
        // UTC 03:00 = Asia/Tokyo 12:00
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 03:00:00', new DateTimeZone('UTC')));
        $filter = new ClockAwareTimeFilter($clock, new DateTimeZone('Asia/Tokyo'));

        // 13:00-15:00 Tokyo time — 12:00 is outside range
        $closure = $filter->createInterval('13:00', '15:00');

        $this->assertFalse($closure());
    }

    /**
     * @testdox TF.9 createInterval evaluates lazily using current clock time
     */
    public function testCreateIntervalEvaluatesLazily(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $filter = new ClockAwareTimeFilter($clock, null);

        $closure = $filter->createInterval('09:00', '17:00');

        $this->assertTrue($closure());

        // Change clock time to outside range
        $clock->setTime(new DateTimeImmutable('2024-01-15 20:00:00'));

        $this->assertFalse($closure());
    }

    /**
     * @testdox TF.10 applyTimeString sets hours and minutes
     */
    public function testApplyTimeStringSetsHoursAndMinutes(): void
    {
        $date = new DateTimeImmutable('2024-01-15 00:00:00');

        $result = ClockAwareTimeFilter::applyTimeString($date, '14:30');

        $this->assertSame('14:30:00', $result->format('H:i:s'));
    }

    /**
     * @testdox TF.11 applyTimeString sets hours minutes and seconds
     */
    public function testApplyTimeStringSetsHoursMinutesAndSeconds(): void
    {
        $date = new DateTimeImmutable('2024-01-15 00:00:00');

        $result = ClockAwareTimeFilter::applyTimeString($date, '14:30:45');

        $this->assertSame('14:30:45', $result->format('H:i:s'));
    }

    /**
     * @testdox TF.12 createInterval returns true at exact start boundary
     */
    public function testCreateIntervalReturnsTrueAtExactStartBoundary(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 09:00:00'));
        $filter = new ClockAwareTimeFilter($clock, null);

        $closure = $filter->createInterval('09:00', '17:00');

        $this->assertTrue($closure());
    }

    /**
     * @testdox TF.13 createInterval returns true at exact end boundary
     */
    public function testCreateIntervalReturnsTrueAtExactEndBoundary(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 17:00:00'));
        $filter = new ClockAwareTimeFilter($clock, null);

        $closure = $filter->createInterval('09:00', '17:00');

        $this->assertTrue($closure());
    }
}
