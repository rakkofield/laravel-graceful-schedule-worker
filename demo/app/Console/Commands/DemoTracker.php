<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class DemoTracker extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:tracker';

    /**
     * @var string
     */
    protected $description = 'Display execution tracker dashboard from Redis';

    /**
     * @return int
     */
    public function handle()
    {
        $cachePrefix = config('cache.prefix', '');

        $this->info('=== Execution Tracker Dashboard ===');
        $this->line('');

        $this->showLastExecutedTimes($cachePrefix);
        $this->showActiveLocks($cachePrefix);
        $this->showKeyFormatGuide();

        return 0;
    }

    /**
     * @param string $cachePrefix
     * @return void
     */
    private function showLastExecutedTimes($cachePrefix)
    {
        $this->info('[Last Executed Times]');

        $pattern = $cachePrefix
            ? "{$cachePrefix}:schedule:tracker:last:*"
            : 'schedule:tracker:last:*';

        $redis = $this->cacheRedis();
        $redisPrefix = $this->redisPrefix();
        $keys = $redis->keys($pattern);

        if (empty($keys)) {
            $this->line('  (no data)');
            $this->line('');
            return;
        }

        $rows = [];
        $now = time();

        foreach ($keys as $fullKey) {
            // Strip the Redis connection prefix for get/ttl commands (phpredis auto-adds it)
            $key = $this->stripPrefix($fullKey, $redisPrefix);
            $value = $redis->get($key);
            $ttl = $redis->ttl($key);

            // Extract mutex name from the full key
            $mutex = $this->extractSuffix($fullKey, 'schedule:tracker:last:');

            $timestamp = (int) $value;
            $formatted = $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : (string) $value;
            $elapsed = $timestamp > 0 ? ($now - $timestamp) . 's ago' : '-';
            $ttlStr = $ttl > 0 ? "{$ttl}s" : ($ttl === -1 ? 'no expiry' : 'expired');

            $rows[] = [$mutex, $formatted, $elapsed, $ttlStr];
        }

        $this->table(
            ['Task (mutex)', 'Last Executed', 'Elapsed', 'TTL'],
            $rows
        );

        $this->line('');
    }

    /**
     * @param string $cachePrefix
     * @return void
     */
    private function showActiveLocks($cachePrefix)
    {
        $this->info('[Active Locks]');

        $pattern = $cachePrefix
            ? "{$cachePrefix}:schedule:tracker:lock:*"
            : 'schedule:tracker:lock:*';

        $redis = $this->cacheRedis();
        $redisPrefix = $this->redisPrefix();
        $keys = $redis->keys($pattern);

        if (empty($keys)) {
            $this->line('  (no active locks)');
            $this->line('');
            return;
        }

        $rows = [];

        foreach ($keys as $fullKey) {
            $key = $this->stripPrefix($fullKey, $redisPrefix);
            $value = $redis->get($key);
            $ttl = $redis->ttl($key);

            $lock = $this->extractSuffix($fullKey, 'schedule:tracker:lock:');
            $ttlStr = $ttl > 0 ? "{$ttl}s" : ($ttl === -1 ? 'no expiry' : 'expired');

            $rows[] = [$lock, $value ?: '(empty)', $ttlStr];
        }

        $this->table(
            ['Lock (mutex:timestamp)', 'Value', 'TTL'],
            $rows
        );

        $this->line('');
    }

    /**
     * @return void
     */
    private function showKeyFormatGuide()
    {
        $this->info('[Key Format Guide]');
        $this->line('  schedule:tracker:last:<mutex>            — Last executed Unix timestamp for a task');
        $this->line('  schedule:tracker:lock:<mutex>:<timestamp> — Active recovery lock (prevents duplicate recovery)');
        $this->line('');
        $this->line('  The <mutex> is derived from the scheduled command signature.');
        $this->line('  The tracker uses these keys to detect missed executions and trigger recovery.');
    }

    /**
     * @param string $key
     * @param string $marker
     * @return string
     */
    private function extractSuffix($key, $marker)
    {
        $pos = strpos($key, $marker);
        if ($pos !== false) {
            return substr($key, $pos + strlen($marker));
        }
        return $key;
    }

    /**
     * Strip the Redis connection prefix from a key returned by keys().
     *
     * @param string $key
     * @param string $prefix
     * @return string
     */
    private function stripPrefix($key, $prefix)
    {
        if ($prefix && strpos($key, $prefix) === 0) {
            return substr($key, strlen($prefix));
        }
        return $key;
    }

    /**
     * @return \Illuminate\Redis\Connections\Connection
     */
    private function cacheRedis()
    {
        $store = config('cache.stores.redis.connection', 'cache');

        return Redis::connection($store);
    }

    /**
     * @return string
     */
    private function redisPrefix()
    {
        return config('database.redis.options.prefix', '');
    }
}
