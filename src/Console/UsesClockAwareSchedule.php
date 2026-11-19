<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * Trait for Kernel that registers ClockAwareSchedule as a separate singleton.
 *
 * Registers ClockAwareSchedule::class, populated via gracefulSchedule(), when the
 * kernel is bootstrapped. Schedule::class stays the plain Schedule that Laravel
 * populates via schedule().
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
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        //
    }

    /**
     * Register ClockAwareSchedule::class after the kernel is bootstrapped.
     *
     * @return void
     */
    public function bootstrap()
    {
        parent::bootstrap();

        $this->registerClockAwareSchedule();
    }

    /**
     * Register ClockAwareSchedule::class once. bootstrap() runs for every artisan
     * call, and re-binding a singleton would drop an already resolved instance.
     *
     * @return void
     */
    protected function registerClockAwareSchedule(): void
    {
        if ($this->app->bound(ClockAwareSchedule::class)) {
            return;
        }

        $this->app->singleton(ClockAwareSchedule::class, function ($app) {
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $schedule = new ClockAwareSchedule(
                $clock,
                config('graceful-scheduler.dispatch', 'local'),
                $this->scheduleTimezone(),
                new TimezoneResolver()
            );
            $schedule->useCache($this->scheduleCache());
            $this->gracefulSchedule($schedule);
            $schedule->stopAcceptingEvents();

            return $schedule;
        });
    }
}
