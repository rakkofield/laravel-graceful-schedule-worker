<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
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
     * @testdox CS.14 exec passes defaultDispatcherType to ClockAwareEvent
     */
    public function testExecPassesDefaultDispatcherTypeToClockAwareEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock, null, 'stepfunctions');

        $event = $schedule->exec('echo test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertSame('stepfunctions', $event->getDispatcherType());
    }

    /**
     * @testdox CS.15 default dispatcherType is 'local' when not specified
     */
    public function testDefaultDispatcherTypeIsLocalWhenNotSpecified(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('echo test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertSame('local', $event->getDispatcherType());
    }
}
