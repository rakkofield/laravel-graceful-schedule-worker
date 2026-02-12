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
     * @testdox TI.11 schedule:run resolves Schedule::class as plain Schedule (not ClockAwareSchedule)
     */
    public function testScheduleRunResolvesPlainSchedule(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertNotInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox TI.12 Schedule::class contains only native events (1 event from schedule())
     */
    public function testScheduleClassContainsOnlyNativeEvents(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();

        $this->assertCount(1, $events);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $events[0]);
    }

    /**
     * @testdox TI.13 dueEvents() on Schedule::class returns native events only
     */
    public function testDueEventsReturnsNativeEventsOnly(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $dueEvents = $schedule->dueEvents($this->app)->all();

        $this->assertCount(1, $dueEvents);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $dueEvents[0]);
    }

    /**
     * @testdox TI.14 filtersPass() returns true on native events from Schedule::class
     */
    public function testFiltersPassOnNativeEvents(): void
    {
        /** @var Schedule $schedule */
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

    /**
     * @testdox TI.21 ClockAwareSchedule::class resolves to ClockAwareSchedule instance
     */
    public function testClockAwareScheduleClassResolvesToClockAwareSchedule(): void
    {
        $schedule = $this->app->make(ClockAwareSchedule::class);
        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox TI.22 ClockAwareSchedule::class contains only ClockAwareEvent events
     */
    public function testClockAwareScheduleClassContainsOnlyClockAwareEvents(): void
    {
        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->app->make(ClockAwareSchedule::class);
        $events = $schedule->events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClockAwareEvent::class, $events[0]);
    }
}
