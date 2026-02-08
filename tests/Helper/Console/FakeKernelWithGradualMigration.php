<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Fake Kernel for testing coexistence of schedule() and gracefulSchedule()
 *
 * Registers native Events via schedule() and ClockAwareEvents via gracefulSchedule()
 * to test gradual migration scenarios.
 */
class FakeKernelWithGradualMigration
{
    use UsesClockAwareSchedule {
        defineConsoleSchedule as public;
    }

    /** @var Container Stub for ConsoleKernel::$app */
    public $app;

    /** @var Event[] Events registered via schedule() */
    public $scheduleEvents = [];

    /** @var Event[] Events registered via gracefulSchedule() */
    public $gracefulScheduleEvents = [];

    /** @var \DateTimeZone|string|null Return value for scheduleTimezone() */
    private $timezone;

    /** @var string|null Return value for scheduleCache() */
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
