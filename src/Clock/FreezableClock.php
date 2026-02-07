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
     * コールバックが例外をスローした場合でも、必ず unfreeze される。
     * ネストした呼び出しはサポートしない（LogicException をスローする）。
     *
     * @param DateTimeImmutable $time freeze する時刻
     * @param callable $callback 実行するコールバック
     * @return mixed コールバックの戻り値
     * @throws \LogicException ネストして呼び出された場合
     */
    public function withFrozenTime(DateTimeImmutable $time, callable $callback)
    {
        if ($this->frozenTime !== null) {
            throw new \LogicException('FreezableClock::withFrozenTime() cannot be nested.');
        }
        $this->frozenTime = $time;
        try {
            return $callback();
        } finally {
            $this->frozenTime = null;
        }
    }
}
