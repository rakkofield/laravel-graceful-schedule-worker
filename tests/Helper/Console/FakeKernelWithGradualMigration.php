<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * schedule() と gracefulSchedule() の共存テスト用 Fake Kernel
 *
 * schedule() で通常の Event を、gracefulSchedule() で ClockAwareEvent を登録し、
 * 段階的移行のシナリオをテストする。
 */
class FakeKernelWithGradualMigration
{
    use UsesClockAwareSchedule {
        defineConsoleSchedule as public;
    }

    /** @var Container ConsoleKernel::$app のスタブ */
    public $app;

    /** @var Event[] schedule() で登録されたイベント */
    public $scheduleEvents = [];

    /** @var Event[] gracefulSchedule() で登録されたイベント */
    public $gracefulScheduleEvents = [];

    /** @var \DateTimeZone|string|null scheduleTimezone() の戻り値 */
    private $timezone;

    /** @var string|null scheduleCache() の戻り値 */
    private $cacheStore;

    /**
     * @param \DateTimeZone|string|null $timezone
     * @param string|null $cacheStore
     */
    public function __construct($timezone = null, $cacheStore = null)
    {
        $this->app = Container::getInstance();
        $this->timezone = $timezone;
        $this->cacheStore = $cacheStore;
    }

    /**
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule($schedule)
    {
        $this->scheduleEvents[] = $schedule->command('native-task');
    }

    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $this->gracefulScheduleEvents[] = $schedule->command('clock-aware-task');
    }

    /**
     * @return \DateTimeZone|string|null
     */
    protected function scheduleTimezone()
    {
        return $this->timezone;
    }

    /**
     * @return string|null
     */
    protected function scheduleCache()
    {
        return $this->cacheStore;
    }
}
