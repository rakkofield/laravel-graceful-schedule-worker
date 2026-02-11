<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Console\TestConsoleKernel;
use RakkoInc\LaravelGracefulScheduleWorker\Console\TestExceptionHandler;

/**
 * schedule:run and ClockAwareSchedule compatibility test
 *
 * Creates a Foundation Application and verifies that schedule:run
 * works correctly through ClockAwareSchedule.
 */
class ScheduleRunCompatibilityIntegrationTest extends TestCase
{
    /** @var \Illuminate\Foundation\Application */
    private $app;

    /** @var Container|null */
    private $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        // Save the existing Container instance
        $this->previousContainer = Container::getInstance();

        // Create Application with test fixture as basePath
        $this->app = new \Illuminate\Foundation\Application(
            __DIR__ . '/../../fixture'
        );
        $this->app->singleton(
            \Illuminate\Contracts\Console\Kernel::class,
            TestConsoleKernel::class
        );
        $this->app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            TestExceptionHandler::class
        );
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->flush();
        }

        // Restore the Container instance
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * @testdox TI.11 schedule:run resolves Schedule::class as ClockAwareSchedule
     */
    public function testScheduleRunResolvesClockAwareSchedule(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox TI.12 schedule() events are native Event and gracefulSchedule() events are ClockAwareEvent
     */
    public function testEventsAreClockAwareEventInstances(): void
    {
        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();

        $this->assertCount(2, $events);
        // schedule() events (registered first) are native Event
        $this->assertNotInstanceOf(ClockAwareEvent::class, $events[0]);
        // gracefulSchedule() events are ClockAwareEvent
        $this->assertInstanceOf(ClockAwareEvent::class, $events[1]);
    }

    /**
     * @testdox TI.13 dueEvents() returns both native and ClockAwareEvent events as due
     */
    public function testDueEventsReturnsEveryMinuteEventAsDue(): void
    {
        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $dueEvents = $schedule->dueEvents($this->app)->all();

        $this->assertCount(2, $dueEvents);
    }

    /**
     * @testdox TI.14 filtersPass() returns true on both native and ClockAwareEvent
     */
    public function testFiltersPassOnDueClockAwareEvent(): void
    {
        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $dueEvents = $schedule->dueEvents($this->app)->all();

        $this->assertNotEmpty($dueEvents);
        foreach ($dueEvents as $event) {
            $this->assertTrue($event->filtersPass($this->app));
        }
    }

    /**
     * @testdox TI.15 Artisan schedule:run exits 0 and processes due events
     */
    public function testArtisanScheduleRunProcessesDueEvents(): void
    {
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $exitCode = $kernel->call('schedule:run');
        $output = $kernel->output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(
            'No scheduled commands are ready to run',
            $output
        );
    }
}
