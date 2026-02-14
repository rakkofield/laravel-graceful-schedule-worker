<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class DemoReset extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:reset';

    /**
     * @var string
     */
    protected $description = 'Clear demo tick data and tracker keys from Redis';

    /**
     * @return int
     */
    public function handle()
    {
        $workers = ['cron', 'graceful'];

        foreach ($workers as $worker) {
            $this->clearWorkerData($worker);
        }

        $this->clearTrackerKeys();

        $this->info('Demo data and tracker keys cleared.');

        return 0;
    }

    /**
     * @param string $worker
     * @return void
     */
    private function clearWorkerData($worker)
    {
        $prefix = "demo:tick:{$worker}";
        $first = Cache::get("{$prefix}:first");
        $last = Cache::get("{$prefix}:last");

        // Clear timeline keys by scanning first..last range
        if ($first && $last) {
            $start = new \DateTime($first);
            $end = new \DateTime($last);
            $end->modify('+1 minute');

            $interval = new \DateInterval('PT1M');
            $period = new \DatePeriod($start, $interval, $end);

            foreach ($period as $dt) {
                $minute = $dt->format('Y-m-d\TH:i');
                Cache::forget("{$prefix}:timeline:{$minute}");
            }
        }

        Cache::forget("{$prefix}:count");
        Cache::forget("{$prefix}:first");
        Cache::forget("{$prefix}:last");

        $this->line("Cleared data for worker '{$worker}'.");
    }

    /**
     * @return void
     */
    private function clearTrackerKeys()
    {
        $cachePrefix = config('cache.prefix', '');

        $patterns = [
            'schedule:tracker:last:*',
            'schedule:tracker:lock:*',
        ];

        foreach ($patterns as $pattern) {
            $fullPattern = $cachePrefix ? "{$cachePrefix}:{$pattern}" : $pattern;
            $keys = Redis::keys($fullPattern);

            if (!empty($keys)) {
                foreach ($keys as $key) {
                    Redis::del($key);
                }
                $this->line("Cleared " . count($keys) . " tracker key(s) matching '{$pattern}'.");
            }
        }
    }
}
