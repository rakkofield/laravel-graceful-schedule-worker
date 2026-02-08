<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;

/**
 * Schedule Spy for testing
 *
 * Allows controlling the return value of dueEvents.
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
        // Bind to Container before calling the parent constructor
        $container = Container::getInstance();
        $container->instance(EventMutex::class, $eventMutex);
        $container->instance(SchedulingMutex::class, $schedulingMutex);

        parent::__construct();
    }

    /**
     * Set events that dueEvents will return
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

    /**
     * Test helper: add an event
     *
     * Adds to the event list returned by Schedule::events().
     *
     * @param Event $event
     * @return void
     */
    public function addEvent(Event $event): void
    {
        $this->events[] = $event;
    }
}
