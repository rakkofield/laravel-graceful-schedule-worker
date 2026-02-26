<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Exception;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * Decorator that adds execution tracking functionality.
 *
 * Wraps an inner Dispatcher and handles the following responsibilities:
 * - Lock acquisition (preventing duplicate execution)
 * - Execution recording (markExecuted)
 * - Failure logging
 */
class TrackingDispatcher implements ScheduleDispatcherInterface
{
    /** @var ScheduleDispatcherInterface */
    private $inner;

    /** @var ExecutionTrackerInterface */
    private $tracker;

    /** @var LoggerInterface */
    private $logger;

    /** @var ClockInterface */
    private $clock;

    /** @var callable */
    private $skippedResultFactory;

    /**
     * @param ScheduleDispatcherInterface $inner Inner dispatcher
     * @param ExecutionTrackerInterface $tracker Execution tracker
     * @param LoggerInterface $logger Logger
     * @param ClockInterface $clock Clock
     * @param callable $skippedResultFactory Factory for creating SkippedDispatchResultInterface instances
     */
    public function __construct(
        ScheduleDispatcherInterface $inner,
        ExecutionTrackerInterface $tracker,
        LoggerInterface $logger,
        ClockInterface $clock,
        callable $skippedResultFactory
    ) {
        $this->inner = $inner;
        $this->tracker = $tracker;
        $this->logger = $logger;
        $this->clock = $clock;
        $this->skippedResultFactory = $skippedResultFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt
    ): DispatchResultInterface {
        // 1. Acquire lock (cache connection failure propagates as exception and stops the worker)
        $lockAcquired = $this->tracker->acquireLock($event, $dueAt);

        if (!$lockAcquired) {
            $this->logger->debug('Lock not acquired, skipping dispatch', [
                'event' => $event->mutexName(),
                'dueAt' => $dueAt->format(DateTimeInterface::ATOM),
            ]);

            /** @var DispatchResultInterface $result */
            $result = ($this->skippedResultFactory)(
                $event->mutexName(),
                (string) $event->command,
                'lock_not_acquired',
                $this->clock->now(),
                $event->getDispatcherType()
            );

            return $result;
        }

        // 2. Delegate to inner dispatcher
        try {
            $result = $this->inner->dispatchEvent($event, $dueAt);
        } catch (\Throwable $e) {
            $this->tryReleaseLock($event, $dueAt);
            throw $e;
        }

        // 3. Validate result type (programming error - must propagate)
        $this->assertKnownResultType($result, $event);

        // 4. Track based on result
        try {
            $this->handleResult($result, $event, $dueAt);
        } catch (Exception $e) {
            // markExecuted failure does not affect the dispatch itself, so continue with warning
            $this->logger->error('Failed to track execution result', [
                'event' => $event->mutexName(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        $this->inner->cleanup();
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        $this->inner->stopAll();
    }

    /**
     * Process the dispatch result.
     *
     * Handles SkippedDispatchResultInterface from the inner dispatcher (e.g., withoutOverlapping).
     * When adding new DispatchResultInterface subtypes, this method must also be updated.
     *
     * @param DispatchResultInterface $result
     * @param ClockAwareEvent $event
     * @param DateTimeInterface $dueAt
     * @return void
     */
    private function handleResult(
        DispatchResultInterface $result,
        ClockAwareEvent $event,
        DateTimeInterface $dueAt
    ): void {
        if ($result instanceof StartedDispatchResultInterface) {
            $this->logger->info('Event dispatched', [
                'event' => $event->mutexName(),
                'dispatcher_type' => $result->getDispatcherType(),
                'dueAt' => $dueAt->format(DateTimeInterface::ATOM),
            ]);
            $this->tracker->markExecuted($event, $dueAt);
            return;
        }

        if ($result instanceof AlreadyRunningDispatchResultInterface) {
            $this->logger->info('Event already running, skipped new execution', [
                'event' => $event->mutexName(),
                'dispatcher_type' => $result->getDispatcherType(),
                'dueAt' => $dueAt->format(DateTimeInterface::ATOM),
            ]);
            $this->tracker->markExecuted($event, $dueAt);
            return;
        }

        if ($result instanceof SkippedDispatchResultInterface) {
            $this->logger->info('Event skipped by inner dispatcher', [
                'event' => $event->mutexName(),
                'dispatcher_type' => $result->getDispatcherType(),
                'reason' => $result->getReason(),
                'dueAt' => $dueAt->format(DateTimeInterface::ATOM),
            ]);
            $this->tryReleaseLock($event, $dueAt);
            return;
        }

        if ($result instanceof FailedDispatchResultInterface) {
            $this->tryReleaseLock($event, $dueAt);
            $this->handleDispatchFailure($event, $result);
            return;
        }
    }

    /**
     * Assert that the result is a known type.
     *
     * Called before the try/catch block so that the exception propagates
     * without being swallowed by the tracking error handler.
     *
     * @param DispatchResultInterface $result
     * @param ClockAwareEvent $event
     * @return void
     * @throws Exception if the result type is unknown
     */
    private function assertKnownResultType(
        DispatchResultInterface $result,
        ClockAwareEvent $event
    ): void {
        if (
            $result instanceof StartedDispatchResultInterface
            || $result instanceof AlreadyRunningDispatchResultInterface
            || $result instanceof SkippedDispatchResultInterface
            || $result instanceof FailedDispatchResultInterface
        ) {
            return;
        }

        throw new Exception(sprintf(
            'Unexpected dispatch result type: %s (dispatcher: %s, event: %s)',
            get_class($result),
            $result->getDispatcherType(),
            $event->mutexName()
        ));
    }

    /**
     * Attempt to release the execution lock, logging on failure.
     *
     * @param ClockAwareEvent $event
     * @param DateTimeInterface $dueAt
     * @return void
     */
    private function tryReleaseLock(ClockAwareEvent $event, DateTimeInterface $dueAt): void
    {
        try {
            $this->tracker->releaseLock($event, $dueAt);
        } catch (Exception $e) {
            $this->logger->error('Failed to release lock', [
                'event' => $event->mutexName(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Handle dispatch failure.
     *
     * @param ClockAwareEvent $event
     * @param FailedDispatchResultInterface $result
     * @return void
     */
    private function handleDispatchFailure(
        ClockAwareEvent $event,
        FailedDispatchResultInterface $result
    ): void {
        $context = [
            'event' => $event->mutexName(),
            'dispatcher_type' => $result->getDispatcherType(),
            'error' => $result->getError(),
        ];

        $exception = $result->getException();
        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        $this->logger->error('Failed to dispatch event', $context);
    }
}
