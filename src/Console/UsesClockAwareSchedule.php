<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Trait for Kernel that registers ClockAwareSchedule as a separate singleton.
 *
 * Overrides defineConsoleSchedule() to register two separate singletons:
 * - Schedule::class — plain Schedule populated via schedule() (delegated to parent)
 * - ClockAwareSchedule::class — ClockAwareSchedule populated via gracefulSchedule()
 *
 * This separation ensures schedule:run only processes native events,
 * and schedule:graceful-work only processes ClockAwareEvents.
 *
 * Prerequisite: Must be used in a class that extends Illuminate\Foundation\Console\Kernel.
 * (Depends on $this->app, scheduleTimezone(), scheduleCache())
 */
trait UsesClockAwareSchedule // @phpstan-ignore trait.unused
{
    /**
     * Schedule definition using ClockAwareEvent.
     * Gradually migrate tasks from schedule() to this method.
     *
     * @param ClockAwareSchedule $schedule
     * @return void
     */
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        //
    }

    /**
     * Register Schedule::class and ClockAwareSchedule::class as separate singletons.
     *
     * parent::defineConsoleSchedule() registers Schedule::class (plain Schedule + schedule()).
     * This method additionally registers ClockAwareSchedule::class for graceful events only.
     *
     * @return void
     */
    protected function defineConsoleSchedule()
    {
        parent::defineConsoleSchedule();

        $this->app->singleton(ClockAwareSchedule::class, function ($app) {
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $schedule = new ClockAwareSchedule(
                $clock,
                $this->scheduleTimezone(),
                config('graceful-scheduler.dispatch', 'local')
            );
            $schedule->useCache($this->scheduleCache());
            $this->gracefulSchedule($schedule);

            return $schedule;
        });
    }
}
