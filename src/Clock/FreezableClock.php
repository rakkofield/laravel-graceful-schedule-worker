<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;

class FreezableClock implements ClockInterface
{
    /** @var ClockInterface */
    private $inner;

    /** @var DateTimeImmutable|null */
    private $frozenTime = null;

    /**
     * @param ClockInterface $inner
     */
    public function __construct(ClockInterface $inner)
    {
        $this->inner = $inner;
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozenTime !== null ? $this->frozenTime : $this->inner->now();
    }

    /**
     * 指定時刻で freeze した状態でコールバックを実行
     *
     * @param DateTimeImmutable $time freeze する時刻
     * @param callable $callback 実行するコールバック
     * @return mixed コールバックの戻り値
     */
    public function withFrozenTime(DateTimeImmutable $time, callable $callback)
    {
        $this->frozenTime = $time;
        try {
            return $callback();
        } finally {
            $this->frozenTime = null;
        }
    }
}
