<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class DemoSlowTask extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:slow-task {--duration=90 : Duration in seconds}';

    /**
     * @var string
     */
    protected $description = 'Simulate a slow task for overlap demonstration';

    /**
     * @return int
     */
    public function handle()
    {
        $duration = (int) $this->option('duration');
        $minute = date('Y-m-d\TH:i');
        $prefix = 'demo:slowtask';
        $ttl = 3600;

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

        $this->info("[slow-task] execution #{$count} started at {$minute} (duration: {$duration}s)");

        sleep($duration);

        $this->info("[slow-task] execution #{$count} finished");

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
