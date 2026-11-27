<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Prints the commands of the tasks in each schedule. Used by WithScheduleE2ETest only.
 */
class ListSchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'e2e:list-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print the tasks run by the worker and by schedule:run';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        foreach ($this->laravel->make(ClockAwareSchedule::class)->events() as $event) {
            $this->line('graceful: ' . $event->command);
        }

        foreach ($this->laravel->make(Schedule::class)->events() as $event) {
            $this->line('schedule: ' . $event->command);
        }

        return 0;
    }
}
