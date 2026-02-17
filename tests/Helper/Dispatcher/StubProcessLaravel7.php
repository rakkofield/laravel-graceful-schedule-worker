<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Symfony\Component\Process\Process;

/**
 * StubProcess for Laravel 7+ (Symfony 5) — typed signal/stop signatures.
 */
class StubProcessLaravel7 extends Process
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
     * @return void
     */
    public function signal(int $signal): void
    {
        $this->doSignal($signal);
    }

    /**
     * @param float $timeout
     * @param int|null $signal
     * @return int|null
     */
    public function stop(float $timeout = 10, int $signal = null): ?int
    {
        return $this->doStop();
    }
}
