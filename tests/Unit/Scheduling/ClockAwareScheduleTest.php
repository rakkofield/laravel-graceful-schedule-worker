<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FreezableClock;

class ClockAwareScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);

        $container->bind(EventMutex::class, function () {
            return new FakeEventMutex();
        });

        $container->bind(SchedulingMutex::class, function () {
            return new FakeSchedulingMutex();
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox CS.1 command() returns a ClockAwareEvent
     */
    public function testReturnsClockAwareEventFromCommand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->command('php artisan test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.2 exec() returns a ClockAwareEvent
     */
    public function testReturnsClockAwareEventFromExec(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('ls -la');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.3 Can create ClockAwareEvents from multiple methods
     */
    public function testCreatesClockAwareEventsFromMultipleMethods(): void
    {
        $fixedTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $clock = new FixedClock($fixedTime);
        $schedule = new ClockAwareSchedule($clock);

        $event1 = $schedule->command('php artisan test1');
        $event2 = $schedule->command('php artisan test2');
        $event3 = $schedule->exec('ls -la');

        $this->assertInstanceOf(ClockAwareEvent::class, $event1);
        $this->assertInstanceOf(ClockAwareEvent::class, $event2);
        $this->assertInstanceOf(ClockAwareEvent::class, $event3);
    }

    /**
     * @testdox CS.4 exec() handles parameters correctly
     */
    public function testExecHandlesParametersCorrectly(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('command', ['--foo' => 'bar', '--baz']);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.5 command() handles parameters correctly
     */
    public function testCommandHandlesParametersCorrectly(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->command('php artisan test', ['--option' => 'value']);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.6 evaluateAt freezes the event's Clock
     */
    public function testEvaluateAtFreezesEventClock(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $clock = new FixedClock($innerTime);
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('echo test');

        $schedule->evaluateAt($frozenTime, function () use ($event, $frozenTime) {
            // Verify the ClockAwareEvent's clock (= eventClock) returns the frozen time
            // Since expressionPasses is protected, verify clock->now() indirectly
            $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
            $reflection->setAccessible(true);
            $eventClock = $reflection->getValue($event);

            $this->assertEquals($frozenTime, $eventClock->now());
        });
    }

    /**
     * @testdox CS.7 Unfreezes after evaluateAt completes
     */
    public function testEvaluateAtUnfreezesAfterCallback(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $clock = new FixedClock($innerTime);
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('echo test');

        $schedule->evaluateAt($frozenTime, function () {
            // no-op
        });

        // After evaluateAt completes, the event returns to the live clock
        $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
        $reflection->setAccessible(true);
        $eventClock = $reflection->getValue($event);

        $this->assertEquals($innerTime, $eventClock->now());
    }

    /**
     * @testdox CS.8 exec() passes a FreezableClock to the event
     */
    public function testExecPassesFreezableClockToEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('echo test');

        $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
        $reflection->setAccessible(true);
        $eventClock = $reflection->getValue($event);

        $this->assertInstanceOf(FreezableClock::class, $eventClock);
    }

    /**
     * @testdox CS.9 exec() inside withNativeEvents returns an Event (not ClockAwareEvent)
     */
    public function testWithNativeEventsExecReturnsNativeEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = null;
        $schedule->withNativeEvents(function () use ($schedule, &$event) {
            $event = $schedule->exec('ls -la');
        });

        $this->assertInstanceOf(Event::class, $event);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.10 command() inside withNativeEvents returns an Event
     */
    public function testWithNativeEventsCommandReturnsNativeEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = null;
        $schedule->withNativeEvents(function () use ($schedule, &$event) {
            $event = $schedule->command('php artisan test');
        });

        $this->assertInstanceOf(Event::class, $event);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.11 Returns to ClockAwareEvent after withNativeEvents completes
     */
    public function testAfterWithNativeEventsReturnsClockAwareEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('ls -la');
        });

        $event = $schedule->exec('echo test');
        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.12 Mode is restored even if an exception occurs inside withNativeEvents
     */
    public function testWithNativeEventsRestoresModeOnException(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        try {
            $schedule->withNativeEvents(function () {
                throw new \RuntimeException('test exception');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $event = $schedule->exec('echo test');
        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.13 Events from inside and outside withNativeEvents coexist in the same events array
     */
    public function testNativeAndClockAwareEventsCoexistInEventsArray(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('native-command');
        });
        $schedule->exec('clock-aware-command');

        $events = $schedule->events();
        $this->assertCount(2, $events);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $events[0]);
        $this->assertInstanceOf(ClockAwareEvent::class, $events[1]);
    }
}
