<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Schedule;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Trait for Kernel that automatically registers ClockAwareSchedule.
 *
 * Overrides defineConsoleSchedule() to register ClockAwareSchedule as
 * the Schedule singleton. Users only need to implement gracefulSchedule()
 * to get fully type-hinted schedule definitions.
 *
 * Events remaining in schedule() are registered as standard Laravel Events,
 * while events in gracefulSchedule() are registered as ClockAwareEvents.
 * This enables gradual migration on a per-task basis.
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
     * Register ClockAwareSchedule as the Schedule singleton.
     *
     * @return void
     */
    protected function defineConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function ($app) {
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $schedule = new ClockAwareSchedule($clock, $this->scheduleTimezone());
            $schedule->useCache($this->scheduleCache());

            // Events from schedule() are standard Laravel Events (no behavior change)
            $schedule->withNativeEvents(function () use ($schedule) {
                $this->schedule($schedule);
            });

            // Events from gracefulSchedule() are ClockAwareEvents (new behavior)
            $this->gracefulSchedule($schedule);

            return $schedule;
        });

        $this->app->alias(Schedule::class, ClockAwareSchedule::class);
    }
}
