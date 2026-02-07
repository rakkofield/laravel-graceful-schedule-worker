<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

/**
 * テスト用の no-op Sleeper（呼び出し回数を記録する Spy）
 */
class NullSleeper implements SleeperInterface
{
    /** @var int */
    private $callCount = 0;

    /**
     * {@inheritdoc}
     */
    public function sleep(): void
    {
        $this->callCount++;
    }

    /**
     * sleep() の呼び出し回数を取得
     *
     * @return int
     */
    public function getCallCount(): int
    {
        return $this->callCount;
    }
}
