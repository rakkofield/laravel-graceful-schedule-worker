<?php

namespace App\Console;

use App\Console\Commands\Hello;
use App\Console\Commands\LoopHello;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;
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
    ];

    /**
     * Define the application's standard command schedule.
     *
     * Tasks registered here remain as native Laravel Events (not ClockAwareEvent).
     * This demonstrates the gradual migration pattern — tasks that have not yet
     * been migrated to gracefulSchedule() continue to work as before.
     *
     * @param  Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Example: a task that has not yet been migrated to gracefulSchedule().
        // It runs as a standard Laravel scheduled event (no graceful shutdown support).
        $schedule->exec('echo "native-task: not yet migrated"')->everyMinute();
    }

    /**
     * Define the application's graceful command schedule.
     *
     * Tasks registered here are ClockAwareEvents with graceful shutdown support.
     * Migrate tasks from schedule() to this method one at a time.
     *
     * @param  ClockAwareSchedule  $schedule
     * @return void
     */
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        // ---------------------------------------------------------------
        // Example 1: Short task with grace period and lifecycle callbacks
        // ---------------------------------------------------------------
        // withGracePeriod(30): Allow up to 30 seconds for the task to finish
        // after receiving SIGTERM before forcefully terminating.
        // Note: appendOutputTo(), before(), onSuccess(), onFailure(), after() are
        // local dispatch only features — they do not work with Step Functions.
        $schedule->command('hello')->everyMinute()
            ->runInBackground()
            ->withGracePeriod(30)
            ->appendOutputTo(storage_path('logs/scheduler.log'))
            ->before(function () {
                Log::info('hello start from Scheduler.');
            })
            ->onSuccess(function () {
                Log::info('hello successful.');
            })
            ->onFailure(function () {
                Log::error('hello failed.');
            })
            ->after(function () {
                Log::info('hello finished.');
            })
            ->withoutOverlapping(10);

        // ---------------------------------------------------------------
        // Example 2: Long-running task with recovery and time window
        // ---------------------------------------------------------------
        // enableRecovery(): If the worker restarts (e.g., deploy), the task
        // resumes execution in the next cycle instead of waiting for the
        // next scheduled time. Compare with withGracePeriod() above.
        // between('08:00', '22:00'): Only run during business hours.
        // This is a clock-aware filter — it uses the injected Clock, not
        // the system clock, so it is testable and deterministic.
        $schedule->command('loop-hello', ['--seconds=70'])->everyMinute()
            ->runInBackground()
            ->enableRecovery()
            ->between('08:00', '22:00')
            ->appendOutputTo(storage_path('logs/scheduler.log'))
            ->before(function () {
                Log::info('loop-hello start from Scheduler.');
            })
            ->onSuccess(function () {
                Log::info('loop-hello successful.');
            })
            ->onFailure(function () {
                Log::error('loop-hello failed.');
            })
            ->after(function () {
                Log::info('loop-hello finished.');
            })
            ->withoutOverlapping(10);

        // ---------------------------------------------------------------
        // Example 3: Step Functions dispatch (commented out)
        // ---------------------------------------------------------------
        // To enable Step Functions dispatch:
        //   1. Install AWS SDK: composer require aws/aws-sdk-php
        //   2. Set SCHEDULE_DISPATCH=stepfunctions in .env
        //   3. Set SCHEDULE_STATE_MACHINE_ARN in .env
        //   4. Uncomment the block below
        //
        // $schedule->command('hello')->everyMinute()
        //     ->dispatchVia('stepfunctions');
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
