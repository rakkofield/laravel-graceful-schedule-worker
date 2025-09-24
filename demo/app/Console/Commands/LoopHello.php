<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LoopHello extends Command
{
    protected $signature = 'loop-hello {--seconds=70 : Number of seconds to run}';

    protected $description = 'Loop Hello world output for specified duration with 1-second intervals';

    public function handle()
    {
        $seconds = (int) $this->option('seconds');

        $this->info("Starting loop hello for {$seconds} seconds...");
        Log::info("Loop hello started for {$seconds} seconds");

        $start = time();
        $count = 0;

        while (time() - $start < $seconds) {
            $count++;
            $elapsed = time() - $start;

            $message = "Hello world! (#{$count}, {$elapsed}s elapsed)";
            $this->line($message);
            Log::info($message);

            sleep(1);
        }

        $this->info("Loop hello completed after {$seconds} seconds! Total outputs: {$count}");
        Log::info("Loop hello completed after {$seconds} seconds with {$count} outputs");
    }
}
