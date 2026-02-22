<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

/**
 * Schedule Spy for testing
 *
 * Allows controlling the return value of dueEvents.
 */
class SpySchedule extends ClockAwareSchedule
{
    /** @var array<ClockAwareEvent> */
    private $dueEventsToReturn = [];

    /** @var int */
    private $evaluateAtCallCount = 0;

    /**
     * @param EventMutex $eventMutex
     * @param SchedulingMutex $schedulingMutex
     * @param ClockInterface $clock
     */
    public function __construct(EventMutex $eventMutex, SchedulingMutex $schedulingMutex, ClockInterface $clock)
    {
        // Bind to Container before calling the parent constructor
        $container = Container::getInstance();
        $container->instance(EventMutex::class, $eventMutex);
        $container->instance(SchedulingMutex::class, $schedulingMutex);

        parent::__construct($clock, 'local', null, new TimezoneResolver());
    }

    /**
     * Set events that dueEvents will return
     *
     * @param array<ClockAwareEvent> $events
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
     * @return array<ClockAwareEvent>
     */
    public function dueEvents($app)
    {
        return $this->dueEventsToReturn;
    }

    /**
     * {@inheritdoc}
     */
    public function evaluateAt(\DateTimeImmutable $time, callable $callback)
    {
        $this->evaluateAtCallCount++;
        return parent::evaluateAt($time, $callback);
    }

    /**
     * Get the number of times evaluateAt() was called.
     *
     * @return int
     */
    public function getEvaluateAtCallCount(): int
    {
        return $this->evaluateAtCallCount;
    }

    /**
     * Test helper: add an event
     *
     * Adds to the event list returned by Schedule::events().
     *
     * @param ClockAwareEvent $event
     * @return void
     */
    public function addEvent(ClockAwareEvent $event): void
    {
        $this->events[] = $event;
    }
}
