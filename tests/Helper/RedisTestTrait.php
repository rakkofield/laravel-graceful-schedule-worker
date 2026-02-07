<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;

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
