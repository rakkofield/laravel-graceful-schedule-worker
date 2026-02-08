<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Kernel に use して ClockAwareSchedule を自動登録する trait
 *
 * defineConsoleSchedule() をオーバーライドし、ClockAwareSchedule を
 * Schedule シングルトンとして登録する。利用側は gracefulSchedule() を
 * 実装するだけで完全な型ヒント付きスケジュール定義が可能。
 *
 * illuminate/foundation に依存しないため、ConsoleKernel のメソッド
 * (scheduleTimezone, scheduleCache) は method_exists で存在確認する。
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
        $container = Container::getInstance();

        $container->singleton(Schedule::class, function () use ($container) {
            /** @var ClockInterface $clock */
            $clock = $container->make(ClockInterface::class);

            /** @var \DateTimeZone|string|null $timezone */
            $timezone = method_exists($this, 'scheduleTimezone') ? $this->scheduleTimezone() : null;

            $schedule = new ClockAwareSchedule($clock, $timezone);

            if (method_exists($this, 'scheduleCache')) {
                $schedule->useCache($this->scheduleCache());
            }

            $this->gracefulSchedule($schedule);

            return $schedule;
        });
    }
}
