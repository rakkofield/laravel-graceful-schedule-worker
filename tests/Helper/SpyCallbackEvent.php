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
     * @var \Throwable|null
     */
    private $exceptionToThrow = null;

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

        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }

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
     * Set an exception to throw when callBeforeCallbacks is called.
     *
     * @param \Throwable $e
     * @return self
     */
    public function throwOnBeforeCallback(\Throwable $e): self
    {
        $this->exceptionToThrow = $e;
        return $this;
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
        $this->exceptionToThrow = null;
    }
}
