<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

/**
 * Illuminate\Contracts\Cache\Repository の Fake 実装
 */
class FakeCacheStore implements Repository
{
    /**
     * @var array<string, mixed>
     */
    private $store = [];

    /**
     * @var Store|null
     */
    private $backingStore;

    /**
     * @param Store|null $backingStore LockProvider として使用する Store
     */
    public function __construct(?Store $backingStore = null)
    {
        $this->backingStore = $backingStore;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return array_key_exists($key, $this->store);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function missing($key)
    {
        return !$this->has($key);
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->store)) {
            return $this->store[$key];
        }
        return $default instanceof Closure ? $default() : $default;
    }

    /**
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull($key, $default = null)
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param DateTimeInterface|DateInterval|int|null $ttl
     * @return bool
     */
    public function put($key, $value, $ttl = null)
    {
        $this->store[$key] = $value;
        return true;
    }

    /**
     * @param array<string, mixed> $values
     * @param DateTimeInterface|DateInterval|int|null $ttl
     * @return bool
     */
    public function putMany(array $values, $ttl = null)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $ttl);
        }
        return true;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param DateTimeInterface|DateInterval|int|null $ttl
     * @return bool
     */
    public function add($key, $value, $ttl = null)
    {
        if ($this->has($key)) {
            return false;
        }
        return $this->put($key, $value, $ttl);
    }

    /**
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        $current = $this->get($key, 0);
        $newValue = $current + $value;
        $this->put($key, $newValue);
        return $newValue;
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
        return $this->put($key, $value);
    }

    /**
     * @param string $key
     * @param DateTimeInterface|DateInterval|int|null $ttl
     * @param Closure $callback
     * @return mixed
     */
    public function remember($key, $ttl, Closure $callback)
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->put($key, $value, $ttl);
        return $value;
    }

    /**
     * @param string $key
     * @param Closure $callback
     * @return mixed
     */
    public function sear($key, Closure $callback)
    {
        return $this->rememberForever($key, $callback);
    }

    /**
     * @param string $key
     * @param Closure $callback
     * @return mixed
     */
    public function rememberForever($key, Closure $callback)
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->forever($key, $value);
        return $value;
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
     * @param string $key
     * @return bool
     */
    public function delete($key)
    {
        return $this->forget($key);
    }

    /**
     * @param iterable<string> $keys
     * @return bool
     */
    public function deleteMultiple($keys)
    {
        foreach ($keys as $key) {
            $this->forget($key);
        }
        return true;
    }

    /**
     * @return bool
     */
    public function clear()
    {
        $this->store = [];
        return true;
    }

    /**
     * @return bool
     */
    public function flush()
    {
        return $this->clear();
    }

    /**
     * @return Store|null
     */
    public function getStore()
    {
        return $this->backingStore;
    }

    /**
     * @param iterable<string> $keys
     * @param mixed $default
     * @return iterable<string, mixed>
     */
    public function getMultiple($keys, $default = null)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     * @param DateTimeInterface|DateInterval|int|null $ttl
     * @return bool
     */
    public function setMultiple($values, $ttl = null)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $ttl);
        }
        return true;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param DateTimeInterface|DateInterval|int|null $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = null)
    {
        return $this->put($key, $value, $ttl);
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
    }
}
