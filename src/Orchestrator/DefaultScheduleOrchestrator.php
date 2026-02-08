<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * Default schedule orchestrator.
 *
 * Executes scheduled events using the Dispatcher pattern.
 *
 * Responsibilities:
 * - Schedule evaluation (checking at second 0 of each minute)
 * - Missed execution detection (getMissedDueIfRecoverable)
 * - dueAt determination
 *
 * Lock acquisition, execution recording, and failure handling are handled by TrackingDispatcher
 */
class DefaultScheduleOrchestrator implements ScheduleOrchestratorInterface
{
    /** @var ScheduleDispatcherInterface */
    private $dispatcher;

    /** @var ClockInterface */
    private $clock;

    /** @var ExecutionTrackerInterface */
    private $tracker;

    /** @var LoggerInterface */
    private $logger;

    /** @var SleeperInterface */
    private $sleeper;

    /**
     * @param ScheduleDispatcherInterface $dispatcher
     * @param ClockInterface $clock Time provider
     * @param ExecutionTrackerInterface $tracker Execution tracker (used for missed execution detection)
     * @param LoggerInterface $logger Logger
     * @param SleeperInterface $sleeper Sleeper
     */
    public function __construct(
        ScheduleDispatcherInterface $dispatcher,
        ClockInterface $clock,
        ExecutionTrackerInterface $tracker,
        LoggerInterface $logger,
        SleeperInterface $sleeper
    ) {
        $this->dispatcher = $dispatcher;
        $this->clock = $clock;
        $this->tracker = $tracker;
        $this->logger = $logger;
        $this->sleeper = $sleeper;
    }

    /**
     * {@inheritdoc}
     */
    public function run(Schedule $schedule, Application $app, callable $shouldContinue): bool
    {
        $lastExecutionStartedAt = null;

        // Check for missed executions once at startup
        $this->checkMissedExecutions($schedule, $app, $this->clock->now());

        while ($shouldContinue()) {
            // Sleep to reduce CPU load
            $this->sleeper->sleep();

            $now = $this->clock->now();
            $currentMinute = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);

            // Dispatch events once at second 0 of each minute
            if (
                (int) $now->format('s') === 0 &&
                $currentMinute != $lastExecutionStartedAt
            ) {
                $lastExecutionStartedAt = $currentMinute;

                $this->evaluateAndDispatch($schedule, $app, $now);
            }

            // Clean up completed processes
            $this->dispatcher->cleanup();
        }

        // Stop running processes on exit
        $this->dispatcher->stopAll();

        return true;
    }

    /**
     * Recover missed task executions.
     *
     * @param Schedule $schedule
     * @param Application $app
     * @param DateTimeInterface $now
     * @return void
     */
    private function checkMissedExecutions(Schedule $schedule, Application $app, DateTimeInterface $now): void
    {
        foreach ($schedule->events() as $event) {
            if (!$this->isRecoverableEvent($event)) {
                continue;
            }

            $missedDue = $this->tracker->getMissedDueIfRecoverable($event, $now);
            if ($missedDue === null) {
                continue;
            }

            $this->recoverMissedEvent($event, $app, $missedDue);
        }
    }

    /**
     * Check whether the event is recoverable.
     *
     * @param Event $event
     * @return bool
     */
    private function isRecoverableEvent(Event $event): bool
    {
        return $event instanceof ClockAwareEvent && $event->isRecoverable();
    }

    /**
     * Recover a missed event execution.
     *
     * @param Event $event
     * @param Application $app
     * @param DateTimeInterface $missedDue
     * @return void
     */
    private function recoverMissedEvent(Event $event, Application $app, DateTimeInterface $missedDue): void
    {
        $this->logger->info('[GracefulScheduleWorker] Recovering missed event', [
            'event' => $event->mutexName(),
            'due' => $missedDue->format('Y-m-d H:i:s'),
        ]);

        // Do not check filtersPass() during recovery.
        // Since the clock is not frozen during recovery, time-based filters such as
        // between()/unlessBetween() would be evaluated at the current time.
        // Evaluating with the current time against a past dueAt would produce incorrect results.
        $this->dispatcher->dispatchEvent($event, $app, $missedDue);
    }

    /**
     * Evaluate due events and dispatch them.
     *
     * For ClockAwareSchedule, freezes the time via evaluateAt() to ensure
     * dueEvents() and filtersPass() are evaluated at the same time.
     * For regular Schedule, evaluates without freezing.
     *
     * @param Schedule $schedule
     * @param Application $app
     * @param \DateTimeImmutable $now
     * @return void
     */
    private function evaluateAndDispatch(
        Schedule $schedule,
        Application $app,
        \DateTimeImmutable $now
    ): void {
        $doEvaluate = function () use ($schedule, $app, $now) {
            /** @var array<Event> $events */
            $events = $schedule->dueEvents($app);
            foreach ($events as $event) {
                try {
                    if (!$event->filtersPass($app)) {
                        $this->logger->debug('[GracefulScheduleWorker] Event skipped by filters', [
                            'event' => $event->mutexName(),
                        ]);
                        continue;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                        '[GracefulScheduleWorker] filtersPass threw exception, skipping event',
                        [
                            'event' => $event->mutexName(),
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]
                    );
                    continue;
                }
                $this->dispatcher->dispatchEvent($event, $app, $now);
            }
        };

        if ($schedule instanceof ClockAwareSchedule) {
            $schedule->evaluateAt($now, $doEvaluate);
        } else {
            $doEvaluate();
        }
    }
}
