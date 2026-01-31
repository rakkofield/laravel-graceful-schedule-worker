<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;

/**
 * テスト用 Schedule スパイ
 *
 * dueEvents の戻り値を制御可能にします。
 */
class SpySchedule extends Schedule
{
    /** @var array<Event> */
    private $dueEventsToReturn = [];

    /**
     * @param EventMutex $eventMutex
     * @param SchedulingMutex $schedulingMutex
     */
    public function __construct(EventMutex $eventMutex, SchedulingMutex $schedulingMutex)
    {
        // Container にバインドしてから親のコンストラクタを呼ぶ
        $container = Container::getInstance();
        $container->instance(EventMutex::class, $eventMutex);
        $container->instance(SchedulingMutex::class, $schedulingMutex);

        parent::__construct();
    }

    /**
     * dueEvents が返すイベントを設定
     *
     * @param array<Event> $events
     * @return void
     */
    public function setDueEvents(array $events): void
    {
        $this->dueEventsToReturn = $events;
    }

    /**
     * {@inheritdoc}
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return array<Event>
     */
    public function dueEvents($app)
    {
        return $this->dueEventsToReturn;
    }
}
