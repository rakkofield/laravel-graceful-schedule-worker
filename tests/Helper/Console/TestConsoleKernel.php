<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class TestConsoleKernel extends ConsoleKernel
{
    use UsesClockAwareSchedule;

    /**
     * @param ClockAwareSchedule $schedule
     * @return void
     */
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $schedule->exec('echo graceful-task')->everyMinute()
            ->withGracePeriod(30);
    }

    /**
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule($schedule)
    {
        $schedule->exec('echo native-task')->everyMinute();
    }

    /**
     * @return void
     */
    protected function commands()
    {
        // No additional commands needed for integration tests
    }
}
