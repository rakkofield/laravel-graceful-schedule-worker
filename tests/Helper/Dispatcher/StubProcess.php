<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Symfony\Component\Process\Process;

/**
 * Process stub whose running state, signals and stop() are controlled by the test.
 */
class StubProcess extends Process
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
