<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use DateTimeInterface;
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
    public function run(ClockAwareSchedule $schedule, Application $app, callable $shouldContinue): bool
    {
        $lastExecutionStartedAt = null;

        // Check for missed executions once at startup
        $this->checkMissedExecutions($schedule, $app, $this->clock->now());

        try {
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
        } finally {
            // Stop running processes on exit
            $this->dispatcher->stopAll();
        }

        return true;
    }

    /**
     * Recover missed task executions.
     *
     * @param ClockAwareSchedule $schedule
     * @param Application $app
     * @param DateTimeInterface $now
     * @return void
     */
    private function checkMissedExecutions(ClockAwareSchedule $schedule, Application $app, DateTimeInterface $now): void
    {
        foreach ($schedule->events() as $event) {
            try {
                if (!$this->isRecoverableEvent($event)) {
                    continue;
                }

                $missedDue = $this->tracker->getMissedDueIfRecoverable($event, $now);
                if ($missedDue === null) {
                    continue;
                }

                $this->recoverMissedEvent($event, $app, $missedDue);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Failed to check/recover missed event',
                    [
                        'event' => $event->mutexName(),
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]
                );
            }
        }
    }

    /**
     * Check whether the event is recoverable.
     *
     * @param ClockAwareEvent $event
     * @return bool
     */
    private function isRecoverableEvent(ClockAwareEvent $event): bool
    {
        return $event->isRecoverable();
    }

    /**
     * Recover a missed event execution.
     *
     * @param ClockAwareEvent $event
     * @param Application $app
     * @param DateTimeInterface $missedDue
     * @return void
     */
    private function recoverMissedEvent(ClockAwareEvent $event, Application $app, DateTimeInterface $missedDue): void
    {
        $this->logger->info('Recovering missed event', [
            'event' => $event->mutexName(),
            'due' => $missedDue->format(\DateTimeInterface::ATOM),
        ]);

        // Do not check filtersPass() during recovery.
        // Since the clock is not frozen during recovery, time-based filters such as
        // between()/unlessBetween() would be evaluated at the current time.
        // Evaluating with the current time against a past dueAt would produce incorrect results.
        // Return value is intentionally not checked here.
        // TrackingDispatcher handles result logging (failures are logged as warnings).
        $this->dispatcher->dispatchEvent($event, $app, $missedDue);
    }

    /**
     * Evaluate due events and dispatch them.
     *
     * Freezes the time via evaluateAt() to ensure
     * dueEvents() and filtersPass() are evaluated at the same time.
     *
     * @param ClockAwareSchedule $schedule
     * @param Application $app
     * @param \DateTimeImmutable $now
     * @return void
     */
    private function evaluateAndDispatch(
        ClockAwareSchedule $schedule,
        Application $app,
        \DateTimeImmutable $now
    ): void {
        $doEvaluate = function () use ($schedule, $app, $now) {
            /** @var array<ClockAwareEvent> $events */
            $events = $schedule->dueEvents($app);
            foreach ($events as $event) {
                try {
                    if (!$event->filtersPass($app)) {
                        $this->logger->debug('Event skipped by filters', [
                            'event' => $event->mutexName(),
                        ]);
                        continue;
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'filtersPass threw exception, skipping event',
                        [
                            'event' => $event->mutexName(),
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]
                    );
                    continue;
                }
                // dispatchEvent errors propagate intentionally.
                // TrackingDispatcher handles result-based failures (logging, tracking).
                // Infrastructure failures (e.g. cache connection) stop the worker,
                // as continuing in a partially functional state could cause missed tracking.
                $this->dispatcher->dispatchEvent($event, $app, $now);
            }
        };

        $schedule->evaluateAt($now, $doEvaluate);
    }
}
