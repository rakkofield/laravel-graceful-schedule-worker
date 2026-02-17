<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class DemoTick extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:tick {--worker=graceful : Worker name (cron or graceful)}';

    /**
     * @var string
     */
    protected $description = 'Record a tick to Redis for the specified worker';

    /**
     * @return int
     */
    public function handle()
    {
        $worker = $this->option('worker');
        $minute = date('Y-m-d\TH:i');
        $ttl = 3600;

        $prefix = "demo:tick:{$worker}";

        // Increment global counter
        $count = Cache::increment("{$prefix}:count");
        $this->setTtl("{$prefix}:count", $ttl);

        // Record this minute with actual execution timestamp
        Cache::put("{$prefix}:timeline:{$minute}", date('Y-m-d\TH:i:s'), $ttl);

        // Update first (only if not set)
        if (!Cache::has("{$prefix}:first")) {
            Cache::put("{$prefix}:first", $minute, $ttl);
        }

        // Always update last
        Cache::put("{$prefix}:last", $minute, $ttl);

        $this->info("[{$worker}] tick #{$count} at {$minute}");

        return 0;
    }

    /**
     * @param string $key
     * @param int $ttl
     * @return void
     */
    private function setTtl($key, $ttl)
    {
        $cachePrefix = config('cache.prefix', '');
        $redisKey = $cachePrefix ? "{$cachePrefix}:{$key}" : $key;
        Redis::expire($redisKey, $ttl);
    }
}
