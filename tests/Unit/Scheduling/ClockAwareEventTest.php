<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;

class ClockAwareEventTest extends TestCase
{
    /**
     * @var FakeEventMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutex = new FakeEventMutex();
    }

    /**
     * @testdox CE.1 Can construct an instance with an injected Clock
     */
    public function testCanInjectClock(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CE.2 withGracePeriod returns self for method chaining
     */
    public function testCanSetGracePeriod(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $result = $event->withGracePeriod(30);

        $this->assertSame($event, $result, 'withGracePeriod should return self for method chaining');
    }

    /**
     * @testdox CE.3 enableRecovery returns self for method chaining
     */
    public function testCanEnableRecovery(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $result = $event->enableRecovery();

        $this->assertSame($event, $result, 'enableRecovery should return self for method chaining');
    }

    /**
     * @testdox CE.4 By default, recoverable is false and gracePeriod is null
     */
    public function testRecoverableIsFalseByDefault(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertFalse($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox CE.2.1 withGracePeriod sets recoverable to true
     */
    public function testWithGracePeriodSetsRecoverableToTrue(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->withGracePeriod(30);

        $this->assertTrue($event->isRecoverable());
    }

    /**
     * @testdox CE.2.2 withGracePeriod sets the correct DateInterval
     */
    public function testWithGracePeriodSetsCorrectInterval(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->withGracePeriod(30);

        $gracePeriod = $event->getGracePeriod();
        $this->assertInstanceOf(DateInterval::class, $gracePeriod);

        // Convert DateInterval to actual time difference for verification
        $now = new DateTimeImmutable('2024-01-01 12:00:00');
        $later = $now->add($gracePeriod);
        $diff = $later->getTimestamp() - $now->getTimestamp();

        $this->assertEquals(30 * 60, $diff, '30 minutes = 1800 seconds');
    }

    /**
     * @testdox CE.2.3 withGracePeriod(null) sets unlimited recovery
     */
    public function testWithGracePeriodWithNullSetsUnlimited(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->withGracePeriod(null);

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox CE.2.4 withGracePeriod(0) sets recoverable without a grace period
     */
    public function testWithGracePeriodZeroSetsRecoverableWithoutGracePeriod(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->withGracePeriod(0);

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox CE.3.1 enableRecovery sets recoverable with unlimited grace period
     */
    public function testEnableRecoverySetsRecoverableWithUnlimitedGrace(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->enableRecovery();

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox CE.5 dispatchVia returns self for method chaining
     */
    public function testDispatchViaReturnsSelfForMethodChaining(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $result = $event->dispatchVia('local');

        $this->assertSame($event, $result);
    }

    /**
     * @testdox CE.6 getDispatcherType returns 'local' by default
     */
    public function testGetDispatcherTypeReturnsLocalByDefault(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertSame('local', $event->getDispatcherType());
    }

    /**
     * @testdox CE.7 dispatchVia sets the dispatcherType
     */
    public function testDispatchViaSetsDispatcherType(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->dispatchVia('stepfunctions');

        $this->assertEquals('stepfunctions', $event->getDispatcherType());
    }

    /**
     * @testdox CE.8.1 buildProcessCommand uses exec prefix
     */
    public function testBuildProcessCommandUsesExecPrefix(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );
        $event->runInBackground = true;

        $command = $event->buildProcessCommand();

        $this->assertStringStartsWith('exec ', $command);
        $this->assertStringNotContainsString('schedule:finish', $command);
    }

    /**
     * @testdox CE.8.2 buildProcessCommand does not end with &
     */
    public function testBuildProcessCommandDoesNotEndWithAmpersand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );
        $event->runInBackground = true;

        $command = $event->buildProcessCommand();

        $this->assertStringEndsWith('2>&1', $command);
    }

    /**
     * @testdox CE.9 between() evaluates using timezone conversion when timezone is specified
     */
    public function testBetweenWithTimezoneConvertsTimeCorrectly(): void
    {
        // UTC 03:00 = Asia/Tokyo 12:00
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 03:00:00', new \DateTimeZone('UTC')));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            'Asia/Tokyo',
            new TimezoneResolver()
        );

        // Between 11:00-13:00 in Tokyo time (12:00 is within range)
        $event->between('11:00', '13:00');

        $container = new Container();
        $this->assertTrue($event->filtersPass($container));
    }

    /**
     * @testdox CE.9.1 between() returns false when out of range with timezone specified
     */
    public function testBetweenWithTimezoneReturnsFalseWhenOutOfRange(): void
    {
        // UTC 03:00 = Asia/Tokyo 12:00
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 03:00:00', new \DateTimeZone('UTC')));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            'Asia/Tokyo',
            new TimezoneResolver()
        );

        // Between 13:00-15:00 in Tokyo time (12:00 is out of range)
        $event->between('13:00', '15:00');

        $container = new Container();
        $this->assertFalse($event->filtersPass($container));
    }

    /**
     * @testdox CE.10 between() shifts start to previous day when now is on the next day side of midnight crossing
     */
    public function testBetweenMidnightWrapAroundWithNowAfterMidnight(): void
    {
        // Running at 00:30. between('23:00', '01:00') -> start(23:00) > now(00:30), so shift start to previous day
        $clock = new FixedClock(new DateTimeImmutable('2024-01-02 00:30:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->between('23:00', '01:00');

        $container = new Container();
        $this->assertTrue($event->filtersPass($container));
    }

    /**
     * @testdox CE.10.1 between() shifts end to next day when now is on the previous day side of midnight crossing
     */
    public function testBetweenMidnightWrapAroundWithNowBeforeMidnight(): void
    {
        // Running at 23:30. between('23:00', '01:00') -> end(01:00) < start(23:00) and start(23:00) <= now(23:30)
        // -> shift end to next day
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 23:30:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->between('23:00', '01:00');

        $container = new Container();
        $this->assertTrue($event->filtersPass($container));
    }

    /**
     * @testdox CE.11 unlessBetween() evaluates using timezone conversion when timezone is specified
     */
    public function testUnlessBetweenWithTimezoneConvertsTimeCorrectly(): void
    {
        // UTC 03:00 = Asia/Tokyo 12:00
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 03:00:00', new \DateTimeZone('UTC')));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            'Asia/Tokyo',
            new TimezoneResolver()
        );

        // Between 11:00-13:00 in Tokyo time (12:00 is within range, so skipped)
        $event->unlessBetween('11:00', '13:00');

        $container = new Container();
        $this->assertFalse($event->filtersPass($container));
    }

    /**
     * @testdox CE.20 getDispatcherType returns default value from constructor
     */
    public function testGetDispatcherTypeReturnsDefaultValueFromConstructor(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertSame('local', $event->getDispatcherType());
    }

    /**
     * @testdox CE.21 getDispatcherType returns custom default from constructor
     */
    public function testGetDispatcherTypeReturnsCustomDefaultFromConstructor(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'stepfunctions',
            null,
            new TimezoneResolver()
        );

        $this->assertSame('stepfunctions', $event->getDispatcherType());
    }

    /**
     * @testdox CE.22 constructor validates string timezone and stores as DateTimeZone
     */
    public function testConstructorValidatesStringTimezoneAndStoresAsDateTimeZone(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            'Asia/Tokyo',
            new TimezoneResolver()
        );

        $resolved = $event->getResolvedTimezone();
        $this->assertInstanceOf(\DateTimeZone::class, $resolved);
        $this->assertSame('Asia/Tokyo', $resolved->getName());
    }

    /**
     * @testdox CE.23 constructor validates DateTimeZone object timezone
     */
    public function testConstructorValidatesDateTimeZoneObjectTimezone(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $tz = new \DateTimeZone('US/Eastern');
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            $tz,
            new TimezoneResolver()
        );

        $this->assertSame($tz, $event->getResolvedTimezone());
    }

    /**
     * @testdox CE.24 constructor throws InvalidArgumentException for invalid timezone string
     */
    public function testConstructorThrowsInvalidArgumentExceptionForInvalidTimezoneString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone: Invalid/Zone');

        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            'Invalid/Zone',
            new TimezoneResolver()
        );
    }

    /**
     * @testdox CE.25 getResolvedTimezone returns null when no timezone set
     */
    public function testGetResolvedTimezoneReturnsNullWhenNoTimezoneSet(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertNull($event->getResolvedTimezone());
    }

    /**
     * @testdox CE.26 timezone fluent method validates and normalizes to DateTimeZone
     */
    public function testTimezoneFluentMethodValidatesAndNormalizesToDateTimeZone(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->timezone('America/New_York');

        $resolved = $event->getResolvedTimezone();
        $this->assertInstanceOf(\DateTimeZone::class, $resolved);
        $this->assertSame('America/New_York', $resolved->getName());
    }

    /**
     * @testdox CE.27 timezone fluent method throws InvalidArgumentException for invalid timezone
     */
    public function testTimezoneFluentMethodThrowsInvalidArgumentExceptionForInvalidTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone: Bogus/TZ');

        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->timezone('Bogus/TZ');
    }

    /**
     * @testdox CE.28 timezone fluent method accepts DateTimeZone object
     */
    public function testTimezoneFluentMethodAcceptsDateTimeZoneObject(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $tz = new \DateTimeZone('Europe/London');
        $event->timezone($tz);

        $this->assertSame($tz, $event->getResolvedTimezone());
    }

    /**
     * @testdox CE.29 constructor timezone is accessible via public property as DateTimeZone
     */
    public function testConstructorTimezoneIsAccessibleViaPublicPropertyAsDateTimeZone(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            'Asia/Tokyo',
            new TimezoneResolver()
        );

        $this->assertInstanceOf(\DateTimeZone::class, $event->timezone);
        $this->assertSame('Asia/Tokyo', $event->timezone->getName());
    }

    /**
     * @testdox CE.30 getRawCommand returns null by default
     */
    public function testGetRawCommandReturnsNullByDefault(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertNull($event->getRawCommand());
    }

    /**
     * @testdox CE.31 setRawCommand stores value retrievable by getRawCommand
     */
    public function testSetRawCommandStoresValueRetrievableByGetRawCommand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->setRawCommand(['report:daily']);

        $this->assertSame(['report:daily'], $event->getRawCommand());
    }

    /**
     * @testdox CE.32 getEffectiveCommand returns rawCommand when set
     */
    public function testGetEffectiveCommandReturnsRawCommandWhenSet(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->setRawCommand(['report:daily']);

        $this->assertSame(['report:daily'], $event->getEffectiveCommand());
    }

    /**
     * @testdox CE.33 getEffectiveCommand falls back to command when rawCommand is null
     */
    public function testGetEffectiveCommandFallsBackToCommand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertSame(['php', 'artisan', 'test'], $event->getEffectiveCommand());
    }

    /**
     * @testdox CE.33a getEffectiveCommand fallback collapses tabs and multiple spaces
     */
    public function testGetEffectiveCommandFallbackCollapsesWhitespace(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            "php   artisan\treport:daily",
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertSame(['php', 'artisan', 'report:daily'], $event->getEffectiveCommand());
    }

    /**
     * @testdox CE.33b getEffectiveCommand fallback trims surrounding whitespace
     */
    public function testGetEffectiveCommandFallbackTrimsSurroundingWhitespace(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            '  php artisan test  ',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertSame(['php', 'artisan', 'test'], $event->getEffectiveCommand());
    }

    /**
     * @testdox CE.33c getEffectiveCommand returns [command] when command is empty
     */
    public function testGetEffectiveCommandReturnsOriginalWhenCommandIsEmpty(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            '',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertSame([''], $event->getEffectiveCommand());
    }

    /**
     * @testdox CE.34 resolveLockTtlSeconds returns default when withoutOverlapping is not set
     */
    public function testResolveLockTtlSecondsReturnsDefaultWhenNotWithoutOverlapping(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $this->assertSame(3600, $event->resolveLockTtlSeconds(3600));
    }

    /**
     * @testdox CE.35 resolveLockTtlSeconds returns expiresAt in seconds with default overlapping (1440 min)
     */
    public function testResolveLockTtlSecondsReturnsExpiresAtInSecondsWithDefaultOverlapping(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->withoutOverlapping();

        $this->assertSame(86400, $event->resolveLockTtlSeconds(3600));
    }

    /**
     * @testdox CE.36 resolveLockTtlSeconds returns expiresAt in seconds with explicit overlapping (30 min)
     */
    public function testResolveLockTtlSecondsReturnsExpiresAtInSecondsWithExplicitOverlapping(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $event->withoutOverlapping(30);

        $this->assertSame(1800, $event->resolveLockTtlSeconds(3600));
    }

    /**
     * @testdox CE.37 getTaskTimeoutSeconds returns null until timeoutAfter is called
     */
    public function testTaskTimeoutSecondsIsNullByDefault(): void
    {
        $this->assertNull($this->createEventForTimeout()->getTaskTimeoutSeconds());
    }

    /**
     * @testdox CE.38 timeoutAfter stores the declared seconds and is chainable
     */
    public function testTimeoutAfterStoresDeclaredSeconds(): void
    {
        $event = $this->createEventForTimeout();

        $returned = $event->timeoutAfter(1800);

        $this->assertSame($event, $returned);
        $this->assertSame(1800, $event->getTaskTimeoutSeconds());
    }

    /**
     * @testdox CE.39 timeoutAfter rejects a non-positive value
     */
    public function testTimeoutAfterRejectsNonPositiveValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutAfter() expects at least 1 second, got 0');

        $this->createEventForTimeout()->timeoutAfter(0);
    }

    /**
     * @testdox CE.40 timeoutAfter accepts a value inside the withoutOverlapping window
     */
    public function testTimeoutAfterAcceptsValueInsideLockLifetime(): void
    {
        $event = $this->createEventForTimeout();

        $event->withoutOverlapping(30)->timeoutAfter(1799);

        $this->assertSame(1799, $event->getTaskTimeoutSeconds());
    }

    /**
     * @testdox CE.41 timeoutAfter rejects a value reaching the withoutOverlapping window
     */
    public function testTimeoutAfterRejectsValueReachingLockLifetime(): void
    {
        $event = $this->createEventForTimeout();
        $event->withoutOverlapping(30);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutAfter(1800) must be shorter than the withoutOverlapping(30)');

        $event->timeoutAfter(1800);
    }

    /**
     * @testdox CE.42 The contradiction is caught in the reverse chaining order too
     */
    public function testContradictionIsCaughtWhenWithoutOverlappingComesSecond(): void
    {
        $event = $this->createEventForTimeout();
        $event->timeoutAfter(3600);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutAfter(3600) must be shorter than the withoutOverlapping(30)');

        $event->withoutOverlapping(30);
    }

    /**
     * @testdox CE.43 withoutOverlapping still enables the lock and its TTL
     */
    public function testOverriddenWithoutOverlappingKeepsLaravelBehaviour(): void
    {
        $event = $this->createEventForTimeout();

        $event->timeoutAfter(600)->withoutOverlapping(30);

        $this->assertSame(1800, $event->resolveLockTtlSeconds(3600));
        $this->assertSame(600, $event->getTaskTimeoutSeconds());
    }

    private function createEventForTimeout(): ClockAwareEvent
    {
        return new ClockAwareEvent(
            $this->mutex,
            'php artisan test',
            new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00')),
            'local',
            null,
            new TimezoneResolver()
        );
    }
}
