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
 * schedule() に残っているイベントは Laravel 標準の Event として登録され、
 * gracefulSchedule() のイベントは ClockAwareEvent として登録される。
 * これにより、タスク単位での段階的移行が可能。
 *
 * 前提: Illuminate\Foundation\Console\Kernel を extends したクラスで使用すること。
 * ($this->app, scheduleTimezone(), scheduleCache() に依存)
 */
trait UsesClockAwareSchedule // @phpstan-ignore trait.unused
{
    /**
     * ClockAwareEvent を使うスケジュール定義。
     * schedule() から段階的にタスクを移動する。
     *
     * @param ClockAwareSchedule $schedule
     * @return void
     */
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        //
    }

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
            $schedule->useCache($this->scheduleCache());

            // schedule() のイベントは Laravel 標準の Event（振る舞い変化なし）
            $schedule->withNativeEvents(function () use ($schedule) {
                $this->schedule($schedule);
            });

            // gracefulSchedule() のイベントは ClockAwareEvent（新しい振る舞い）
            $this->gracefulSchedule($schedule);

            return $schedule;
        });

        $this->app->alias(Schedule::class, ClockAwareSchedule::class);
    }
}
