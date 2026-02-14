<?php

namespace App\Console;

use App\Console\Commands\DemoReport;
use App\Console\Commands\DemoReset;
use App\Console\Commands\DemoTick;
use App\Console\Commands\Hello;
use App\Console\Commands\LoopHello;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use RakkoInc\LaravelGracefulScheduleWorker\Console\UsesClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class Kernel extends ConsoleKernel
{
    use UsesClockAwareSchedule;

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Hello::class,
        LoopHello::class,
        DemoTick::class,
        DemoReport::class,
        DemoReset::class,
    ];

    /**
     * Define the application's standard command schedule.
     *
     * Tasks registered here run via schedule:run only (singleton separation).
     *
     * @param  Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // cron tick — executed by schedule:run only
        $schedule->command('demo:tick', ['--worker=cron'])->everyMinute()
            ->appendOutputTo(storage_path('logs/scheduler.log'));
    }

    /**
     * Define the application's graceful command schedule.
     *
     * Tasks registered here run via schedule:graceful-work only (singleton separation).
     *
     * @param  ClockAwareSchedule  $schedule
     * @return void
     */
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        // graceful tick — executed by schedule:graceful-work only
        $schedule->command('demo:tick', ['--worker=graceful'])->everyMinute()
            ->runInBackground()
            ->enableRecovery()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // Step Functions example — only when moto endpoint is available
        if (config('graceful-scheduler.stepfunctions.endpoint')) {
            $schedule->exec('echo "hello from stepfunctions"')->everyMinute()
                ->dispatchVia('stepfunctions');
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
