<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
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

    /**
     * @param ScheduleDispatcherInterface $inner Inner dispatcher
     * @param ExecutionTrackerInterface $tracker Execution tracker
     * @param LoggerInterface $logger Logger
     */
    public function __construct(
        ScheduleDispatcherInterface $inner,
        ExecutionTrackerInterface $tracker,
        LoggerInterface $logger
    ) {
        $this->inner = $inner;
        $this->tracker = $tracker;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        // 1. Acquire lock (cache connection failure propagates as exception and stops the worker)
        $lockAcquired = $this->tracker->acquireLock($event, $dueAt);

        if (!$lockAcquired) {
            $this->logger->debug('[GracefulScheduleWorker] Lock not acquired, skipping dispatch', [
                'event' => $event->mutexName(),
                'dueAt' => $dueAt->format(\DateTimeInterface::ATOM),
            ]);

            return new SkippedDispatchResult(
                $event->mutexName(),
                (string) $event->command,
                'lock_not_acquired',
                new DateTimeImmutable()
            );
        }

        // 2. Delegate to inner dispatcher
        $result = $this->inner->dispatchEvent($event, $container, $dueAt);

        // 3. Track based on result
        try {
            $this->handleResult($result, $event, $dueAt);
        } catch (\LogicException $e) {
            // LogicException indicates a programming error, so rethrow as-is
            throw $e;
        } catch (\Exception $e) {
            // markExecuted failure does not affect the dispatch itself, so continue with warning
            $this->logger->warning('[GracefulScheduleWorker] Failed to track execution result', [
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
     * Note: SkippedDispatchResultInterface is only created on acquireLock failure
     * and is never returned from the inner dispatcher, so no handling is needed here.
     * When adding new DispatchResultInterface subtypes, this method must also be updated.
     *
     * @param DispatchResultInterface $result
     * @param Event $event
     * @param DateTimeInterface $dueAt
     * @return void
     */
    private function handleResult(
        DispatchResultInterface $result,
        Event $event,
        DateTimeInterface $dueAt
    ): void {
        if ($result instanceof StartedDispatchResultInterface) {
            $this->logger->info('[GracefulScheduleWorker] Event dispatched', [
                'event' => $event->mutexName(),
                'dispatcher_type' => $result->getDispatcherType(),
                'dueAt' => $dueAt->format(\DateTimeInterface::ATOM),
            ]);
            $this->tracker->markExecuted($event, $dueAt);
            return;
        }

        if ($result instanceof AlreadyRunningDispatchResultInterface) {
            $this->logger->info('[GracefulScheduleWorker] Event already running, skipped new execution', [
                'event' => $event->mutexName(),
                'dispatcher_type' => $result->getDispatcherType(),
                'dueAt' => $dueAt->format(\DateTimeInterface::ATOM),
            ]);
            $this->tracker->markExecuted($event, $dueAt);
            return;
        }

        if ($result instanceof FailedDispatchResultInterface) {
            $this->handleDispatchFailure($event, $result);
            return;
        }

        // Unexpected result type - this indicates a bug
        throw new \LogicException(sprintf(
            'Unexpected dispatch result type: %s (dispatcher: %s, event: %s)',
            get_class($result),
            $result->getDispatcherType(),
            $event->mutexName()
        ));
    }

    /**
     * Handle dispatch failure.
     *
     * @param Event $event
     * @param FailedDispatchResultInterface $result
     * @return void
     */
    private function handleDispatchFailure(
        Event $event,
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

        $this->logger->error('[GracefulScheduleWorker] Failed to dispatch event', $context);
    }
}
