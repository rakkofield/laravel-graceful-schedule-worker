<?php

namespace App\Console;

use App\Console\Commands\DemoLongTask;
use App\Console\Commands\DemoReport;
use App\Console\Commands\DemoReset;
use App\Console\Commands\DemoSlowTask;
use App\Console\Commands\DemoTick;
use App\Console\Commands\DemoTracker;
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
        DemoLongTask::class,
        DemoSlowTask::class,
        DemoReport::class,
        DemoReset::class,
        DemoTracker::class,
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
            ->appendOutputTo('/tmp/scheduler.log');
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
        $scenario = getenv('DEMO_SCENARIO');

        if ($scenario === 'overlap') {
            $schedule->command('demo:slow-task', ['--duration=90'])
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo('/tmp/scheduler.log');
            return;
        }

        if ($scenario === 'signal' || $scenario === 'stepfunctions') {
            $schedule->command('demo:long-task')->everyMinute()
                ->runInBackground()
                ->appendOutputTo('/tmp/scheduler.log');

            if ($scenario === 'stepfunctions' && config('graceful-scheduler.stepfunctions.endpoint')) {
                // Derived timeout: no timeoutAfter(), so the payload carries
                // lock lifetime - lock_release_buffer (3600 - 60 = 3540 by default).
                $schedule->exec('echo "sfn-task-executed"')->everyMinute()
                    ->dispatchVia('stepfunctions');

                // Declared timeout: timeoutAfter() overrides the derivation, but only
                // downwards - it can never outlast the lock. Compare the two
                // timeoutSeconds values with bin/check-stepfunctions.php.
                $schedule->exec('echo "sfn-task-with-timeout"')->everyMinute()
                    ->timeoutAfter(120)
                    ->dispatchVia('stepfunctions');
            }
            return;
        }

        // Default: existing behavior (deploy, compare, default)
        $schedule->command('demo:tick', ['--worker=graceful'])->everyMinute()
            ->runInBackground()
            ->enableRecovery()
            ->appendOutputTo('/tmp/scheduler.log');

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
