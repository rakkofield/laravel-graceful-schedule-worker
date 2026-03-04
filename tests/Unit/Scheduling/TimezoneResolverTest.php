<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TimezoneResolverTest extends TestCase
{
    /**
     * @testdox TR.1 resolve returns null for null input
     */
    public function testResolveReturnsNullForNullInput(): void
    {
        $this->assertNull((new TimezoneResolver())->resolve(null));
    }

    /**
     * @testdox TR.2 resolve returns same DateTimeZone instance for DateTimeZone input
     */
    public function testResolveReturnsSameDateTimeZoneInstance(): void
    {
        $tz = new DateTimeZone('Asia/Tokyo');

        $this->assertSame($tz, (new TimezoneResolver())->resolve($tz));
    }

    /**
     * @testdox TR.3 resolve creates DateTimeZone from valid string
     */
    public function testResolveCreatesDateTimeZoneFromValidString(): void
    {
        $result = (new TimezoneResolver())->resolve('America/New_York');

        $this->assertInstanceOf(DateTimeZone::class, $result);
        $this->assertSame('America/New_York', $result->getName());
    }

    /**
     * @testdox TR.4 resolve creates DateTimeZone from UTC string
     */
    public function testResolveCreatesDateTimeZoneFromUtcString(): void
    {
        $result = (new TimezoneResolver())->resolve('UTC');

        $this->assertInstanceOf(DateTimeZone::class, $result);
        $this->assertSame('UTC', $result->getName());
    }

    /**
     * @testdox TR.5 resolve throws InvalidArgumentException for invalid string
     */
    public function testResolveThrowsInvalidArgumentExceptionForInvalidString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone: Invalid/Zone');

        (new TimezoneResolver())->resolve('Invalid/Zone');
    }

    /**
     * @testdox TR.6 resolve throws InvalidArgumentException with original exception as previous
     */
    public function testResolveThrowsWithOriginalExceptionAsPrevious(): void
    {
        try {
            (new TimezoneResolver())->resolve('Bogus/TZ');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertNotNull($e->getPrevious());
        }
    }
}
