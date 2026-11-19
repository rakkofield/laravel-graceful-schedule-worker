<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Symfony\Component\Process\Process;

/**
 * StubProcess for Symfony Process 6+ (Laravel 9+) — signal() returns static.
 */
class StubProcessSymfony6 extends Process
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
     * @return static
     */
    public function signal(int $signal): static
    {
        $this->doSignal($signal);

        return $this;
    }

    /**
     * @param float $timeout
     * @param int|null $signal
     * @return int|null
     */
    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        return $this->doStop();
    }
}
