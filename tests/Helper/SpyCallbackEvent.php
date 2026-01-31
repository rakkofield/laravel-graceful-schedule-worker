<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Contracts\Container\Container;

/**
 * Spy class to verify callback methods are called.
 */
class SpyCallbackEvent extends Event
{
    /**
     * @var bool
     */
    private $beforeCallbacksCalled = false;

    /**
     * @var bool
     */
    private $afterCallbacksCalled = false;

    /**
     * @param EventMutex $mutex
     * @param string $command
     */
    public function __construct(EventMutex $mutex, string $command)
    {
        parent::__construct($mutex, $command);
    }

    /**
     * @param Container $container
     * @return void
     */
    public function callBeforeCallbacks(Container $container)
    {
        $this->beforeCallbacksCalled = true;
        parent::callBeforeCallbacks($container);
    }

    /**
     * @param Container $container
     * @return void
     */
    public function callAfterCallbacks(Container $container)
    {
        $this->afterCallbacksCalled = true;
        parent::callAfterCallbacks($container);
    }

    /**
     * @return bool
     */
    public function wasBeforeCallbacksCalled(): bool
    {
        return $this->beforeCallbacksCalled;
    }

    /**
     * @return bool
     */
    public function wasAfterCallbacksCalled(): bool
    {
        return $this->afterCallbacksCalled;
    }

    /**
     * Reset spy state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->beforeCallbacksCalled = false;
        $this->afterCallbacksCalled = false;
    }
}
