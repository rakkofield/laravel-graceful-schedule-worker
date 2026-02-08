<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Illuminate\Contracts\Cache\Store;

/**
 * Fake Store that does not implement LockProvider
 *
 * Used to test the RuntimeException in Provider's registerTrackerBindings()
 * when the Store does not implement LockProvider.
 */
class FakeNonLockProviderStore implements Store
{
    /**
     * @param string|array<string> $key
     * @return mixed
     */
    public function get($key)
    {
        return null;
    }

    /**
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        return [];
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        return true;
    }

    /**
     * @param array<string, mixed> $values
     * @param int $seconds
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        return true;
    }

    /**
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        return 0;
    }

    /**
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        return 0;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever($key, $value)
    {
        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function forget($key)
    {
        return true;
    }

    /**
     * @return bool
     */
    public function flush()
    {
        return true;
    }

    /**
     * @return string
     */
    public function getPrefix()
    {
        return '';
    }
}
