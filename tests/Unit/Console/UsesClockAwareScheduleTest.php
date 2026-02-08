<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;

class UsesClockAwareScheduleTest extends TestCase
{
    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->container->bind(EventMutex::class, function () {
            return new FakeEventMutex();
        });

        $this->container->bind(SchedulingMutex::class, function () {
            return new FakeSchedulingMutex();
        });

        $this->container->singleton(ClockInterface::class, function () {
            return new FixedClock(new \DateTimeImmutable('2024-01-15 12:00:00'));
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox T7.1 defineConsoleSchedule registers ClockAwareSchedule as Schedule singleton
     */
    public function testDefineConsoleScheduleRegistersClockAwareSchedule(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule = $this->container->make(Schedule::class);

        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox T7.2 gracefulSchedule receives the ClockAwareSchedule instance
     */
    public function testGracefulScheduleReceivesClockAwareSchedule(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule = $this->container->make(Schedule::class);

        $this->assertSame($schedule, $kernel->receivedSchedule);
    }

    /**
     * @testdox T7.3 scheduleTimezone is passed to ClockAwareSchedule
     */
    public function testScheduleTimezoneIsPassedToClockAwareSchedule(): void
    {
        $kernel = new FakeKernelWithTrait('Asia/Tokyo');
        $kernel->defineConsoleSchedule();

        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->container->make(Schedule::class);

        $event = $schedule->exec('echo test');
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    /**
     * @testdox T7.4 Schedule singleton is resolved only once
     */
    public function testScheduleSingletonIsResolvedOnlyOnce(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule1 = $this->container->make(Schedule::class);
        $schedule2 = $this->container->make(Schedule::class);

        $this->assertSame($schedule1, $schedule2);
    }

    /**
     * @testdox T7.5 ClockAwareSchedule uses injected ClockInterface
     */
    public function testClockAwareScheduleUsesInjectedClock(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->container->make(Schedule::class);

        $schedule->exec('echo every-minute')->everyMinute();
        $schedule->exec('echo daily-three')->dailyAt('03:00');

        $frozenTime = new \DateTimeImmutable('2024-01-15 03:00:00');
        $dueEvents = [];

        $schedule->evaluateAt($frozenTime, function () use ($schedule, &$dueEvents) {
            $app = new \RakkoInc\LaravelGracefulScheduleWorker\FakeApplication();
            $dueEvents = $schedule->dueEvents($app)->all();
        });

        $this->assertCount(2, $dueEvents);
    }

    /**
     * @testdox T7.6 ClockAwareSchedule::class resolves to the same singleton as Schedule::class
     */
    public function testClockAwareScheduleClassResolvesToSameSingleton(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $viaSchedule = $this->container->make(Schedule::class);
        $viaClockAware = $this->container->make(ClockAwareSchedule::class);

        $this->assertSame($viaSchedule, $viaClockAware);
    }
}
