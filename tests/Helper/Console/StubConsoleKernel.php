<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;

/**
 * Lightweight stub of ConsoleKernel for testing UsesClockAwareSchedule trait.
 *
 * Provides the same defineConsoleSchedule() as Laravel's ConsoleKernel
 * so that parent::defineConsoleSchedule() works in the trait.
 *
 * Prerequisite: $this->app must be set before calling defineConsoleSchedule().
 */
class StubConsoleKernel
{
    /** @var Container */
    public $app;

    /**
     * @return void
     */
    protected function defineConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function () {
            $schedule = new Schedule($this->scheduleTimezone());
            $schedule->useCache($this->scheduleCache());
            $this->schedule($schedule);
            return $schedule;
        });
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
