<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FreezableClock;

class ClockAwareSchedule extends Schedule
{
    /** @var ClockInterface コンストラクタで注入された元の clock */
    protected $clock;

    /** @var FreezableClock 全 ClockAwareEvent で共有される freezable な clock ラッパー */
    private $eventClock;

    /** @var bool true の場合、exec() は親の Event を生成する */
    private $nativeEventMode = false;

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
     * Native events モードでコールバックを実行
     *
     * コールバック内の command()/exec() 呼び出しは親の Event を生成する。
     * ClockAwareEvent ではなく Laravel 標準の Event が使われるため、
     * Clock の振る舞いは変化しない。
     *
     * @param callable $callback
     * @return void
     */
    public function withNativeEvents(callable $callback)
    {
        $this->nativeEventMode = true;
        try {
            $callback();
        } finally {
            $this->nativeEventMode = false;
        }
    }

    /**
     * Add a new Artisan command event to the schedule.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return ClockAwareEvent|Event
     */
    public function command($command, array $parameters = [])
    {
        if (class_exists($command)) {
            /** @var \Illuminate\Console\Command $resolved */
            $resolved = Container::getInstance()->make($command);
            $command = $resolved->getName();
        }

        return $this->exec(
            Application::formatCommandString((string) $command),
            $parameters
        );
    }

    /**
     * Add a new command event to the schedule.
     *
     * @param string $command
     * @param array<string, mixed> $parameters
     * @return ClockAwareEvent|Event
     */
    public function exec($command, array $parameters = [])
    {
        if ($this->nativeEventMode) {
            return parent::exec($command, $parameters);
        }

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
     * Orchestrator から呼ばれ、dueEvents() + filtersPass() の評価を
     * 同一時刻で行うためのエントリーポイント。
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
