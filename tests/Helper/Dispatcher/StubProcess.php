<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Symfony\Component\Process\Process;

/**
 * Process Stub for testing
 *
 * Extends Symfony Process and stubs out process state.
 */
class StubProcess extends Process
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

    /**
     * @param bool $running Initial running flag
     */
    public function __construct(bool $running = true)
    {
        parent::__construct(['echo', 'stub']);
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
     * @param int $timeout
     * @param int|null $signal
     * @return int|null
     */
    public function stop(float $timeout = 10, int $signal = null): ?int
    {
        $this->stopped = true;
        $this->running = false;
        $this->exitCode = 143; // SIGTERM
        return $this->exitCode;
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
     * @param int $signal
     * @return void
     */
    public function signal(int $signal): void
    {
        $this->receivedSignals[] = $signal;

        if ($this->terminateOnSignal && $signal === SIGTERM) {
            $this->running = false;
            $this->exitCode = 143;
        }
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
}
