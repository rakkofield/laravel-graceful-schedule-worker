<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Schedule;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Kernel に use して ClockAwareSchedule を自動登録する trait
 *
 * defineConsoleSchedule() をオーバーライドし、ClockAwareSchedule を
 * Schedule シングルトンとして登録する。利用側は gracefulSchedule() を
 * 実装するだけで完全な型ヒント付きスケジュール定義が可能。
 *
 * 前提: Illuminate\Foundation\Console\Kernel を extends したクラスで使用すること。
 * ($this->app, scheduleTimezone(), scheduleCache() に依存)
 */
trait UsesClockAwareSchedule // @phpstan-ignore trait.unused
{
    /**
     * @param ClockAwareSchedule $schedule
     * @return void
     */
    abstract protected function gracefulSchedule(ClockAwareSchedule $schedule);

    /**
     * ClockAwareSchedule を Schedule シングルトンとして登録する。
     *
     * @return void
     */
    protected function defineConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function ($app) {
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $schedule = new ClockAwareSchedule($clock, $this->scheduleTimezone());

            $this->gracefulSchedule($schedule->useCache($this->scheduleCache()));

            return $schedule;
        });

        $this->app->alias(Schedule::class, ClockAwareSchedule::class);
    }
}
