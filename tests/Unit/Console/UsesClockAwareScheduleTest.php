<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
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

        $this->container->singleton('config', function () {
            return new \Illuminate\Config\Repository([
                'graceful-scheduler' => ['dispatch' => 'local'],
            ]);
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox UCS.1 defineConsoleSchedule registers plain Schedule as Schedule::class singleton
     */
    public function testDefineConsoleScheduleRegistersPlainSchedule(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule = $this->container->make(Schedule::class);

        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertNotInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox UCS.2 gracefulSchedule receives the ClockAwareSchedule instance
     */
    public function testGracefulScheduleReceivesClockAwareSchedule(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule = $this->container->make(ClockAwareSchedule::class);

        $this->assertSame($schedule, $kernel->receivedSchedule);
    }

    /**
     * @testdox UCS.3 scheduleTimezone is passed to ClockAwareSchedule
     */
    public function testScheduleTimezoneIsPassedToClockAwareSchedule(): void
    {
        $kernel = new FakeKernelWithTrait('Asia/Tokyo');
        $kernel->defineConsoleSchedule();

        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->container->make(ClockAwareSchedule::class);

        $event = $schedule->exec('echo test');
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    /**
     * @testdox UCS.4 Both singletons are resolved only once
     */
    public function testBothSingletonsAreResolvedOnlyOnce(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule1 = $this->container->make(Schedule::class);
        $schedule2 = $this->container->make(Schedule::class);
        $this->assertSame($schedule1, $schedule2);

        $clockAware1 = $this->container->make(ClockAwareSchedule::class);
        $clockAware2 = $this->container->make(ClockAwareSchedule::class);
        $this->assertSame($clockAware1, $clockAware2);
    }

    /**
     * @testdox UCS.5 ClockAwareSchedule uses injected ClockInterface
     */
    public function testClockAwareScheduleUsesInjectedClock(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->container->make(ClockAwareSchedule::class);

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
     * @testdox UCS.6 Schedule::class and ClockAwareSchedule::class are separate instances
     */
    public function testScheduleAndClockAwareScheduleAreSeparateInstances(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $viaSchedule = $this->container->make(Schedule::class);
        $viaClockAware = $this->container->make(ClockAwareSchedule::class);

        $this->assertNotSame($viaSchedule, $viaClockAware);
    }

    /**
     * @testdox UCS.7 schedule() events are native Event type (not ClockAwareEvent)
     */
    public function testScheduleEventsAreNativeEventType(): void
    {
        $kernel = new FakeKernelWithGradualMigration();
        $kernel->defineConsoleSchedule();

        $this->container->make(Schedule::class);

        $this->assertCount(1, $kernel->scheduleEvents);
        $this->assertInstanceOf(Event::class, $kernel->scheduleEvents[0]);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $kernel->scheduleEvents[0]);
    }

    /**
     * @testdox UCS.8 gracefulSchedule() events are ClockAwareEvent type
     */
    public function testGracefulScheduleEventsAreClockAwareEventType(): void
    {
        $kernel = new FakeKernelWithGradualMigration();
        $kernel->defineConsoleSchedule();

        $this->container->make(ClockAwareSchedule::class);

        $this->assertCount(1, $kernel->gracefulScheduleEvents);
        $this->assertInstanceOf(ClockAwareEvent::class, $kernel->gracefulScheduleEvents[0]);
    }

    /**
     * @testdox UCS.9 schedule() and gracefulSchedule() events are separated into different Schedule instances
     */
    public function testEventsAreSeparatedIntoDifferentScheduleInstances(): void
    {
        $kernel = new FakeKernelWithGradualMigration();
        $kernel->defineConsoleSchedule();

        /** @var Schedule $nativeSchedule */
        $nativeSchedule = $this->container->make(Schedule::class);
        /** @var ClockAwareSchedule $gracefulSchedule */
        $gracefulSchedule = $this->container->make(ClockAwareSchedule::class);

        // Native schedule contains only native events
        $nativeEvents = $nativeSchedule->events();
        $this->assertCount(1, $nativeEvents);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $nativeEvents[0]);

        // Graceful schedule contains only ClockAwareEvents
        $gracefulEvents = $gracefulSchedule->events();
        $this->assertCount(1, $gracefulEvents);
        $this->assertInstanceOf(ClockAwareEvent::class, $gracefulEvents[0]);
    }

    /**
     * @testdox UCS.10 Default empty implementation is used when gracefulSchedule() is not overridden
     */
    public function testDefaultGracefulScheduleIsEmpty(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->container->make(ClockAwareSchedule::class);

        $this->assertCount(0, $schedule->events());
    }

    /**
     * @testdox UCS.11 config dispatch type is passed to ClockAwareSchedule events
     */
    public function testConfigDispatchTypeIsPassedToClockAwareScheduleEvents(): void
    {
        // Register a config repository with graceful-scheduler.dispatch = 'stepfunctions'
        $this->container->singleton('config', function () {
            return new \Illuminate\Config\Repository([
                'graceful-scheduler' => ['dispatch' => 'stepfunctions'],
            ]);
        });

        $kernel = new FakeKernelWithGradualMigration();
        $kernel->defineConsoleSchedule();

        // Resolve the ClockAwareSchedule singleton to trigger schedule registration
        $this->container->make(ClockAwareSchedule::class);

        // gracefulSchedule() events should have 'stepfunctions' as default dispatcherType
        $this->assertCount(1, $kernel->gracefulScheduleEvents);
        $event = $kernel->gracefulScheduleEvents[0];
        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertSame('stepfunctions', $event->getDispatcherType());
    }
}
