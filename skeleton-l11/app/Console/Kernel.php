<?php

namespace App\Console;

use App\Console\Commands\Hello;
use App\Console\Commands\ListSchedules;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;
use RakkoInc\LaravelGracefulScheduleWorker\Console\UsesClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Console kernel added to an application created from the Laravel 11+ skeleton,
 * which has no app/Console/Kernel.php (see README). Same schedule as skeleton/.
 */
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
        ListSchedules::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  ClockAwareSchedule  $schedule
     * @return void
     */
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
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
            });

        // Used by WithoutOverlappingE2ETest (E2E.15) only.
        if (config('e2e.overlap')) {
            // sh -c keeps the write inside the task: the scheduler appends its own
            // output redirection (> /dev/null) to the command line.
            $schedule->exec('sh -c ' . escapeshellarg('echo bg >> ' . storage_path('logs/e2e_overlap_bg.log')))
                ->everyMinute()->runInBackground()->withoutOverlapping();
            $schedule->exec('sh -c ' . escapeshellarg('echo fg >> ' . storage_path('logs/e2e_overlap_fg.log')))
                ->everyMinute()->withoutOverlapping();
        }
    }

    /**
     * Tasks not yet migrated to gracefulSchedule() (native Event).
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule($schedule)
    {
        $schedule->exec('echo native-task')->everyMinute();
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
