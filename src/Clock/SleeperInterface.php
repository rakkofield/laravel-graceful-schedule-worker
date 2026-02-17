<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

interface SleeperInterface
{
    /**
     * Execute a sleep.
     *
     * @return void
     */
    public function sleep(): void;
}
