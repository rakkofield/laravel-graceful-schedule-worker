<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;

/**
 * Lightweight stub of ConsoleKernel for testing UsesClockAwareSchedule trait.
 *
 * bootstrap() registers Schedule::class with resolveConsoleSchedule(), as
 * FoundationServiceProvider does, so that tests only need to call bootstrap().
 *
 * Prerequisite: $this->app must be set before calling bootstrap().
 */
class StubConsoleKernel
{
    /** @var Container */
    public $app;

    /**
     * @return void
     */
    public function bootstrap()
    {
        $this->app->singleton(Schedule::class, function () {
            return $this->resolveConsoleSchedule();
        });
    }

    /**
     * Same as Laravel's ConsoleKernel::resolveConsoleSchedule().
     *
     * @return Schedule
     */
    public function resolveConsoleSchedule()
    {
        $schedule = new Schedule($this->scheduleTimezone());
        $schedule->useCache($this->scheduleCache());
        $this->schedule($schedule);
        return $schedule;
    }

    /**
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule($schedule)
    {
        //
    }

    /**
     * @return \DateTimeZone|string|null
     */
    protected function scheduleTimezone()
    {
        return null;
    }

    /**
     * @return string|null
     */
    protected function scheduleCache()
    {
        return null;
    }
}
