<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DemoLongTask extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:long-task {--steps=8 : Number of processing steps}';

    /**
     * @var string
     */
    protected $description = 'Simulate a long-running task with SIGTERM handling';

    /**
     * @var bool
     */
    private $shouldStop = false;

    /**
     * @return int
     */
    public function handle()
    {
        $steps = (int) $this->option('steps');
        $prefix = 'demo:longtask';
        $ttl = 3600;

        pcntl_signal(SIGTERM, function () {
            $this->shouldStop = true;
            $this->line('[long-task] SIGTERM received - finishing current step...');
        });

        Cache::put("{$prefix}:status", 'running', $ttl);
        Cache::put("{$prefix}:steps", $steps, $ttl);
        $this->line("[long-task] Starting {$steps}-step task");

        for ($i = 1; $i <= $steps; $i++) {
            pcntl_signal_dispatch();

            // Simulate work (1 second per step)
            sleep(1);

            Cache::put("{$prefix}:last-step", $i, $ttl);

            if ($this->shouldStop) {
                $this->line("[long-task] Step {$i}/{$steps} done (graceful stop)");
                $this->line("[long-task] Stopped gracefully after step {$i}/{$steps}");
                Cache::put("{$prefix}:status", 'stopped-gracefully', $ttl);
                return 0;
            }

            $this->line("[long-task] Step {$i}/{$steps} done");
        }

        Cache::put("{$prefix}:status", 'completed', $ttl);
        $this->line("[long-task] All {$steps} steps completed");

        return 0;
    }
}
