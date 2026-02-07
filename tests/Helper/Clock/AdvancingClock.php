<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use DateTimeImmutable;

/**
 * now() を呼ぶたびに指定秒数進む Clock 実装
 */
class AdvancingClock implements ClockInterface
{
    /** @var DateTimeImmutable */
    private $current;

    /** @var int */
    private $advanceSeconds;

    /**
     * @param DateTimeImmutable $start 開始時刻
     * @param int $advanceSeconds 1回の now() 呼び出しで進む秒数
     */
    public function __construct(DateTimeImmutable $start, int $advanceSeconds = 1)
    {
        $this->current = $start;
        $this->advanceSeconds = $advanceSeconds;
    }

    public function now(): DateTimeImmutable
    {
        $result = $this->current;
        $this->current = $this->current->modify("+{$this->advanceSeconds} seconds");
        return $result;
    }
}
