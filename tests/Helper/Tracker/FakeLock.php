<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Illuminate\Contracts\Cache\Lock;

/**
 * Fake implementation of the Lock interface
 */
class FakeLock implements Lock
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $seconds;

    /**
     * @var array<string, bool>
     */
    private $locks;

    /**
     * @var string|null
     */
    private $owner;

    /**
     * @param string $name
     * @param int $seconds
     * @param array<string, bool> $locks Passed by reference
     * @param string|null $owner
     */
    public function __construct(string $name, int $seconds, array &$locks, ?string $owner = null)
    {
        $this->name = $name;
        $this->seconds = $seconds;
        $this->locks = &$locks;
        $this->owner = $owner ?? uniqid('lock_', true);
    }

    /**
     * @param callable|null $callback
     * @return mixed
     */
    public function get($callback = null)
    {
        if ($this->acquire()) {
            if ($callback !== null) {
                try {
                    return $callback();
                } finally {
                    $this->release();
                }
            }
            return true;
        }
        return false;
    }

    /**
     * @param int $seconds
     * @param callable|null $callback
     * @return mixed
     */
    public function block($seconds, $callback = null)
    {
        return $this->get($callback);
    }

    /**
     * @return bool
     */
    public function acquire()
    {
        if (isset($this->locks[$this->name])) {
            return false;
        }
        $this->locks[$this->name] = true;
        return true;
    }

    /**
     * @return bool
     */
    public function release()
    {
        if (isset($this->locks[$this->name])) {
            unset($this->locks[$this->name]);
            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    public function owner()
    {
        return $this->owner;
    }

    /**
     * @return void
     */
    public function forceRelease()
    {
        unset($this->locks[$this->name]);
    }

    /**
     * @return bool
     */
    public function isOwnedByCurrentProcess()
    {
        return isset($this->locks[$this->name]);
    }
}
