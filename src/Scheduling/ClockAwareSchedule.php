<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FreezableClock;

class ClockAwareSchedule extends Schedule
{
    /**
     * @var ClockInterface
     */
    protected $clock;

    /**
     * @var FreezableClock
     */
    private $eventClock;

    /**
     * @param ClockInterface $clock
     * @param \DateTimeZone|string|null $timezone
     */
    public function __construct(ClockInterface $clock, $timezone = null)
    {
        parent::__construct($timezone);
        $this->clock = $clock;
        $this->eventClock = new FreezableClock($clock);
    }

    /**
     * Add a new command event to the schedule.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return ClockAwareEvent
     */
    public function exec($command, array $parameters = [])
    {
        if (count($parameters)) {
            $command .= ' ' . $this->compileParameters($parameters);
        }

        $event = new ClockAwareEvent($this->eventMutex, $command, $this->eventClock, $this->timezone);

        $this->events[] = $event;

        return $event;
    }

    /**
     * 指定時刻で freeze した状態でコールバックを実行
     *
     * @param DateTimeImmutable $time 評価基準時刻
     * @param callable $callback 実行するコールバック
     * @return mixed
     */
    public function evaluateAt(DateTimeImmutable $time, callable $callback)
    {
        return $this->eventClock->withFrozenTime($time, $callback);
    }
}
