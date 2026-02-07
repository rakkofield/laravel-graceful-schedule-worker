<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

interface SleeperInterface
{
    /**
     * スリープを実行する
     *
     * @return void
     */
    public function sleep(): void;
}
