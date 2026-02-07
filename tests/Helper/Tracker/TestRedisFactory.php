<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;

/**
 * テスト用の Redis Factory 実装
 *
 * Illuminate\Cache\RedisStore が必要とする Factory インターフェースを実装。
 * テスト環境で直接 Redis に接続する実装。
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
