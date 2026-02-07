<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Redis;

trait RedisTestTrait
{
    /**
     * @return string
     */
    private function getRedisHost(): string
    {
        return getenv('REDIS_HOST') ?: '127.0.0.1';
    }

    /**
     * @return int
     */
    private function getRedisPort(): int
    {
        return (int) (getenv('REDIS_PORT') ?: 6379);
    }

    /**
     * @return bool
     */
    private function isRedisAvailable(): bool
    {
        try {
            $redis = new Redis();
            $redis->connect($this->getRedisHost(), $this->getRedisPort(), 1.0);
            $redis->ping();
            $redis->close();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $prefix
     * @return Repository
     */
    private function createRedisCache(string $prefix = 'test:'): Repository
    {
        $factory = new TestRedisFactory($this->getRedisHost(), $this->getRedisPort());
        $store = new RedisStore($factory, $prefix);

        return new Repository($store);
    }
}
