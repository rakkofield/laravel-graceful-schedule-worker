<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

class Sleeper implements SleeperInterface
{
    /** @var int */
    private $microseconds;

    /**
     * @param int $microseconds スリープ時間（マイクロ秒、1以上）
     * @throws \InvalidArgumentException $microseconds が 0 以下の場合
     */
    public function __construct(int $microseconds)
    {
        if ($microseconds <= 0) {
            throw new \InvalidArgumentException(
                "Sleep microseconds must be greater than 0, got {$microseconds}"
            );
        }

        $this->microseconds = $microseconds;
    }

    /**
     * {@inheritdoc}
     */
    public function sleep(): void
    {
        usleep($this->microseconds);
    }
}
