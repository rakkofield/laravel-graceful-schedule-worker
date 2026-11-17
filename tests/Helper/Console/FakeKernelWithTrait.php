<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Fake Kernel for testing the UsesClockAwareSchedule trait
 *
 * Extends StubConsoleKernel so that parent::defineConsoleSchedule() works.
 */
class FakeKernelWithTrait extends StubConsoleKernel
{
    use UsesClockAwareSchedule {
        defineConsoleSchedule as public;
    }

    /** @var Container Stub for ConsoleKernel::$app */
    public $app;

    /** @var ClockAwareSchedule|null Schedule received in gracefulSchedule() */
    public $receivedSchedule;

    /** @var callable|null Called with the schedule in gracefulSchedule(), to define tasks */
    public $defineTasks;

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
        //
    }

    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $this->receivedSchedule = $schedule;

        if ($this->defineTasks !== null) {
            ($this->defineTasks)($schedule);
        }
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
