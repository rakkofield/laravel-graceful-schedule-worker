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
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox UCS.1 defineConsoleSchedule registers ClockAwareSchedule as Schedule singleton
     */
    public function testDefineConsoleScheduleRegistersClockAwareSchedule(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule = $this->container->make(Schedule::class);

        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox UCS.2 gracefulSchedule receives the ClockAwareSchedule instance
     */
    public function testGracefulScheduleReceivesClockAwareSchedule(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $schedule = $this->container->make(Schedule::class);

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
        $schedule = $this->container->make(Schedule::class);

        $event = $schedule->exec('echo test');
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    /**
     * @testdox UCS.4 Schedule singleton is resolved only once
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
     * @testdox UCS.5 ClockAwareSchedule uses injected ClockInterface
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
     * @testdox UCS.6 ClockAwareSchedule::class resolves to the same singleton as Schedule::class
     */
    public function testClockAwareScheduleClassResolvesToSameSingleton(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        $viaSchedule = $this->container->make(Schedule::class);
        $viaClockAware = $this->container->make(ClockAwareSchedule::class);

        $this->assertSame($viaSchedule, $viaClockAware);
    }

    /**
     * @testdox UCS.7 schedule() のイベントが Event 型（ClockAwareEvent でない）になる
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
     * @testdox UCS.8 gracefulSchedule() のイベントが ClockAwareEvent 型になる
     */
    public function testGracefulScheduleEventsAreClockAwareEventType(): void
    {
        $kernel = new FakeKernelWithGradualMigration();
        $kernel->defineConsoleSchedule();

        $this->container->make(Schedule::class);

        $this->assertCount(1, $kernel->gracefulScheduleEvents);
        $this->assertInstanceOf(ClockAwareEvent::class, $kernel->gracefulScheduleEvents[0]);
    }

    /**
     * @testdox UCS.9 両メソッドのイベントが同じ Schedule に共存する
     */
    public function testBothMethodsEventsCoexistInSameSchedule(): void
    {
        $kernel = new FakeKernelWithGradualMigration();
        $kernel->defineConsoleSchedule();

        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->container->make(Schedule::class);
        $events = $schedule->events();

        $this->assertCount(2, $events);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $events[0]);
        $this->assertInstanceOf(ClockAwareEvent::class, $events[1]);
    }

    /**
     * @testdox UCS.10 gracefulSchedule() をオーバーライドしない場合、空のデフォルト実装が使われる
     */
    public function testDefaultGracefulScheduleIsEmpty(): void
    {
        $kernel = new FakeKernelWithTrait();
        $kernel->defineConsoleSchedule();

        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->container->make(Schedule::class);

        $this->assertCount(0, $schedule->events());
    }
}
