<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Cron\CronExpression;
use Cron\FieldFactory;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Cache-based ExecutionTracker implementation.
 */
class CacheExecutionTracker implements ExecutionTrackerInterface
{
    private const PREFIX = 'schedule:tracker:';
    private const DEFAULT_TTL_SECONDS = 86400; // 24 hours

    /**
     * @var Repository
     */
    private $cache;

    /**
     * @var LockProvider
     */
    private $lockProvider;

    /**
     * @var int
     */
    private $lockTtl;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array<string, Lock>
     */
    private $acquiredLocks = [];

    /**
     * @param Repository $cache
     * @param LockProvider $lockProvider
     * @param LoggerInterface $logger
     * @param int $lockTtl Lock TTL in seconds
     * @throws InvalidArgumentException If lockTtl is not a positive integer
     */
    public function __construct(
        Repository $cache,
        LockProvider $lockProvider,
        LoggerInterface $logger,
        int $lockTtl = 3600
    ) {
        if ($lockTtl <= 0) {
            throw new InvalidArgumentException('lockTtl must be a positive integer');
        }
        $this->cache = $cache;
        $this->lockProvider = $lockProvider;
        $this->logger = $logger;
        $this->lockTtl = $lockTtl;
    }

    /**
     * {@inheritdoc}
     */
    public function markExecuted(ClockAwareEvent $event, DateTimeInterface $dueAt): void
    {
        $key = $this->getLastExecutedKey($event);
        $ttl = $this->calculateTtl($event);
        $this->cache->put($key, $dueAt->getTimestamp(), $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(ClockAwareEvent $event, DateTimeInterface $now): ?DateTimeInterface
    {
        $lastExecutedDue = $this->getLastExecutedDue($event);
        if ($lastExecutedDue === null) {
            return null; // First execution, no missed executions
        }

        // Calculate the previous run date from the cron expression (exceptions propagate as-is)
        try {
            $cron = new CronExpression($event->expression, new FieldFactory());
            $previousRunDate = $cron->getPreviousRunDate($now);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid cron expression: %s', $event->expression),
                0,
                $e
            );
        }
        $missedDue = DateTimeImmutable::createFromMutable($previousRunDate);

        // Check for missed execution (compare by timestamp)
        if ($missedDue->getTimestamp() <= $lastExecutedDue->getTimestamp()) {
            return null; // No missed execution
        }

        // Grace period check
        $gracePeriod = $event->getGracePeriod();
        if ($gracePeriod !== null) {
            $deadline = $missedDue->add($gracePeriod);
            if ($now->getTimestamp() > $deadline->getTimestamp()) {
                $this->logger->warning('[GracefulScheduleWorker] Skipping missed event: grace period exceeded', [
                    'event' => $event->mutexName(),
                    'missedDue' => $missedDue->format('Y-m-d H:i:s'),
                    'deadline' => $deadline->format('Y-m-d H:i:s'),
                ]);
                return null; // Grace period exceeded
            }
        }

        return $missedDue;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(ClockAwareEvent $event, DateTimeInterface $dueAt): bool
    {
        $key = $this->getLockKey($event, $dueAt);
        $lock = $this->lockProvider->lock($key, $this->lockTtl);

        if ($lock->get()) {
            $this->acquiredLocks[$key] = $lock;
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(ClockAwareEvent $event, DateTimeInterface $dueAt): void
    {
        $key = $this->getLockKey($event, $dueAt);

        if (isset($this->acquiredLocks[$key])) {
            $this->acquiredLocks[$key]->release();
            unset($this->acquiredLocks[$key]);
        }
    }

    /**
     * Get the last executed due time.
     *
     * @param ClockAwareEvent $event Target event
     * @return DateTimeImmutable|null Last executed due time (null if never executed)
     */
    private function getLastExecutedDue(ClockAwareEvent $event): ?DateTimeImmutable
    {
        $key = $this->getLastExecutedKey($event);
        $timestamp = $this->cache->get($key);

        if ($timestamp === null || !is_numeric($timestamp)) {
            return null;
        }

        return new DateTimeImmutable('@' . (int) $timestamp);
    }

    /**
     * @param ClockAwareEvent $event
     * @return string
     */
    private function getLastExecutedKey(ClockAwareEvent $event): string
    {
        return self::PREFIX . 'last:' . $event->mutexName();
    }

    /**
     * @param ClockAwareEvent $event
     * @param DateTimeInterface $dueAt
     * @return string
     */
    private function getLockKey(ClockAwareEvent $event, DateTimeInterface $dueAt): string
    {
        return self::PREFIX . 'lock:' . $event->mutexName() . ':' . $dueAt->getTimestamp();
    }

    /**
     * @param ClockAwareEvent $event
     * @return int
     */
    private function calculateTtl(ClockAwareEvent $event): int
    {
        $gracePeriod = $event->getGracePeriod();
        if ($gracePeriod !== null) {
            $seconds = $this->dateIntervalToSeconds($gracePeriod);
            return $seconds * 2;
        }
        return self::DEFAULT_TTL_SECONDS;
    }

    /**
     * @param DateInterval $interval
     * @return int
     */
    private function dateIntervalToSeconds(DateInterval $interval): int
    {
        $days = ($interval->days !== false && $interval->days !== 0) ? $interval->days : $interval->d;
        return ($days * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }
}
