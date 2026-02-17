<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;

/**
 * Redis Factory implementation for testing
 *
 * Implements the Factory interface required by Illuminate\Cache\RedisStore.
 * Connects directly to Redis in the test environment.
 */
class TestRedisFactory implements Factory
{
    /**
     * @var PhpRedisConnection
     */
    private $connection;

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host, int $port)
    {
        $redis = new Redis();
        $redis->connect($host, $port);
        $this->connection = new PhpRedisConnection($redis);
    }

    /**
     * Get a Redis connection by name.
     *
     * @param string|null $name
     * @return \Illuminate\Redis\Connections\Connection
     */
    public function connection($name = null)
    {
        return $this->connection;
    }
}
