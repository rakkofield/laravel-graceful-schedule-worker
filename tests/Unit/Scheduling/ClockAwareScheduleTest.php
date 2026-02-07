<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;

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
     * @testdox T1.12
     */
    public function testReturnsClockAwareEventFromCommand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->command('php artisan test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox T1.13
     */
    public function testReturnsClockAwareEventFromExec(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('ls -la');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox T1.14
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
     * @testdox T1.15
     */
    public function testExecHandlesParametersCorrectly(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('command', ['--foo' => 'bar', '--baz']);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox T1.16
     */
    public function testCommandHandlesParametersCorrectly(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->command('php artisan test', ['--option' => 'value']);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }
}
