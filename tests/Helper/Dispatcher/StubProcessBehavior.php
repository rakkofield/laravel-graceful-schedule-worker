<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

/**
 * Common logic for StubProcess (version-specific classes use this trait).
 */
trait StubProcessBehavior
{
    /** @var bool */
    private $running = false;

    /** @var bool */
    private $stopped = false;

    /** @var int|null */
    private $exitCode = null;

    /** @var array<int> */
    private $receivedSignals = [];

    /** @var bool */
    private $terminateOnSignal = false;

    /** @var \Throwable|null */
    private $throwOnSignal;

    /**
     * @param bool $running Initial running flag
     */
    protected function initStub(bool $running): void
    {
        $this->running = $running;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->running && !$this->stopped;
    }

    /**
     * @return int|null
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Test helper: set whether the process is running.
     *
     * @param bool $running
     * @return void
     */
    public function setRunning(bool $running): void
    {
        $this->running = $running;
    }

    /**
     * Test helper: check if stop() was called.
     *
     * @return bool
     */
    public function wasStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * @return array<int>
     */
    public function getReceivedSignals(): array
    {
        return $this->receivedSignals;
    }

    /**
     * @param bool $terminate
     * @return void
     */
    public function setTerminateOnSignal(bool $terminate): void
    {
        $this->terminateOnSignal = $terminate;
    }

    /**
     * Configure signal() to throw an exception after recording the signal.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function willThrowOnSignal(\Throwable $exception): void
    {
        $this->throwOnSignal = $exception;
    }

    /**
     * Common stop logic.
     *
     * @return int|null
     */
    protected function doStop(): ?int
    {
        $this->stopped = true;
        $this->running = false;
        $this->exitCode = 143; // SIGTERM
        return $this->exitCode;
    }

    /**
     * Common signal logic.
     *
     * @param int $signal
     * @return void
     */
    protected function doSignal(int $signal): void
    {
        $this->receivedSignals[] = $signal;

        if ($this->terminateOnSignal && $signal === SIGTERM) {
            $this->running = false;
            $this->exitCode = 143;
        }

        if ($this->throwOnSignal !== null) {
            throw $this->throwOnSignal;
        }
    }
}
