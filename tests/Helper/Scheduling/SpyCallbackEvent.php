<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

/**
 * Spy class to verify callback methods are called.
 */
class SpyCallbackEvent extends ClockAwareEvent
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
     * @var bool
     */
    private $afterCallbacksWithExitCodeCalled = false;

    /**
     * @var int|null
     */
    private $afterCallbacksExitCode = null;

    /**
     * @var \Throwable|null
     */
    private $exceptionToThrow = null;

    /**
     * @var \Throwable|null
     */
    private $afterExceptionToThrow = null;

    /**
     * @param EventMutex $mutex
     * @param string $command
     * @param ClockInterface $clock
     */
    public function __construct(EventMutex $mutex, string $command, ClockInterface $clock)
    {
        parent::__construct($mutex, $command, $clock);
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
     * @param Container $container
     * @param int $exitCode
     * @return void
     */
    public function callAfterCallbacksWithExitCode(Container $container, $exitCode)
    {
        $this->afterCallbacksWithExitCodeCalled = true;
        $this->afterCallbacksExitCode = (int) $exitCode;

        if ($this->afterExceptionToThrow !== null) {
            throw $this->afterExceptionToThrow;
        }

        parent::callAfterCallbacksWithExitCode($container, $exitCode);
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
     * Set an exception to throw when callAfterCallbacksWithExitCode is called.
     *
     * @param \Throwable $e
     * @return self
     */
    public function throwOnAfterCallback(\Throwable $e): self
    {
        $this->afterExceptionToThrow = $e;
        return $this;
    }

    /**
     * @return bool
     */
    public function wasAfterCallbacksWithExitCodeCalled(): bool
    {
        return $this->afterCallbacksWithExitCodeCalled;
    }

    /**
     * @return int|null
     */
    public function getAfterCallbacksExitCode(): ?int
    {
        return $this->afterCallbacksExitCode;
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
        $this->afterCallbacksWithExitCodeCalled = false;
        $this->afterCallbacksExitCode = null;
        $this->exceptionToThrow = null;
        $this->afterExceptionToThrow = null;
    }
}
