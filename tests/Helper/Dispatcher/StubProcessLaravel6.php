<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Symfony\Component\Process\Process;

/**
 * StubProcess for Laravel 6 (Symfony 4) — no return type hints on signal/stop.
 */
class StubProcessLaravel6 extends Process
{
    use StubProcessBehavior;

    /**
     * @param bool $running Initial running flag
     */
    public function __construct(bool $running = true)
    {
        parent::__construct(['echo', 'stub']);
        $this->initStub($running);
    }

    /**
     * @param int $signal
     * @return $this
     */
    public function signal($signal)
    {
        $this->doSignal($signal);
        return $this;
    }

    /**
     * @param int $timeout
     * @param int|null $signal
     * @return int|null
     */
    public function stop($timeout = 10, $signal = null)
    {
        return $this->doStop();
    }
}
