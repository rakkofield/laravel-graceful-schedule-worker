<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class E2eRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'e2e:record {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record a run to storage/logs/laravel.log (used by E2E tests)';

    /**
     * Execute the console command.
     *
     * The scheduler sends the task's output to /dev/null and the default log
     * channel is stdout, so the run is written to the single channel.
     *
     * @return mixed
     */
    public function handle()
    {
        Log::channel('single')->info('e2e:record ' . $this->argument('name'));
    }
}
