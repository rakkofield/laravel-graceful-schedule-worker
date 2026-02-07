<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * LockProvider インターフェースの Fake 実装
 *
 * Store インターフェースも実装し、FakeCacheStore と組み合わせて使用する。
 */
class FakeLockProvider implements LockProvider, Store
{
    /**
     * @var array<string, bool>
     */
    private $locks = [];

    /**
     * @var array<string, mixed>
     */
    private $store = [];

    /**
     * @param string $name
     * @param int $seconds
     * @param string|null $owner
     * @return Lock
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new FakeLock($name, $seconds, $this->locks, $owner);
    }

    /**
     * @param string $name
     * @param string $owner
     * @return Lock
     */
    public function restoreLock($name, $owner)
    {
        return new FakeLock($name, 0, $this->locks, $owner);
    }

    // Store interface methods

    /**
     * @param string|array<string> $key
     * @return mixed
     */
    public function get($key)
    {
        if (is_array($key)) {
            return array_intersect_key($this->store, array_flip($key));
        }
        return $this->store[$key] ?? null;
    }

    /**
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->store[$key] ?? null;
        }
        return $result;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        $this->store[$key] = $value;
        return true;
    }

    /**
     * @param array<string, mixed> $values
     * @param int $seconds
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->store[$key] = $value;
        }
        return true;
    }

    /**
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        $current = $this->store[$key] ?? 0;
        $this->store[$key] = $current + $value;
        return $this->store[$key];
    }

    /**
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever($key, $value)
    {
        $this->store[$key] = $value;
        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function forget($key)
    {
        unset($this->store[$key]);
        return true;
    }

    /**
     * @return bool
     */
    public function flush()
    {
        $this->store = [];
        $this->locks = [];
        return true;
    }

    /**
     * @return string
     */
    public function getPrefix()
    {
        return '';
    }

    /**
     * テスト用: ロック状態を取得
     *
     * @return array<string, bool>
     */
    public function getLocks(): array
    {
        return $this->locks;
    }

    /**
     * テスト用: ストアデータを取得
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->store;
    }

    /**
     * テスト用: リセット
     */
    public function reset(): void
    {
        $this->store = [];
        $this->locks = [];
    }
}
