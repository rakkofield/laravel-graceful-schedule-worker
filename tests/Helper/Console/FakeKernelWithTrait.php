<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * UsesClockAwareSchedule trait のテスト用 Fake Kernel
 *
 * ConsoleKernel の $this->app, scheduleTimezone(), scheduleCache() を
 * スタブ実装で提供する。
 */
class FakeKernelWithTrait
{
    use UsesClockAwareSchedule {
        defineConsoleSchedule as public;
    }

    /** @var Container ConsoleKernel::$app のスタブ */
    public $app;

    /** @var ClockAwareSchedule|null gracefulSchedule() で受け取った schedule */
    public $receivedSchedule;

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

    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $this->receivedSchedule = $schedule;
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
