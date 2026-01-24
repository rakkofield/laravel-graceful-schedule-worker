<?php

namespace App\Console;

use App\Console\Commands\Hello;
use App\Console\Commands\LoopHello;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
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
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('hello')->everyMinute()
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

        $schedule->command('loop-hello', ['--seconds=70'])->everyMinute()
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
