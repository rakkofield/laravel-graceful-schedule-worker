<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Carbon\Carbon;
use Cron\CronExpression;
use DateInterval;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Cache を使用した ExecutionTracker 実装
 */
class CacheExecutionTracker implements ExecutionTrackerInterface
{
    private const PREFIX = 'schedule:tracker:';

    /**
     * @var Repository
     */
    private $cache;

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
     * @param int $lockTtl ロックの TTL（秒）
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        Repository $cache,
        int $lockTtl = 3600,
        ?LoggerInterface $logger = null
    ) {
        $this->cache = $cache;
        $this->lockTtl = $lockTtl;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function markExecuted(Event $event, Carbon $dueAt): void
    {
        $key = $this->getLastExecutedKey($event);
        $ttl = $this->calculateTtl($event);
        $this->cache->put($key, $dueAt->timestamp, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function wasMissed(Event $event, Carbon $now): bool
    {
        $lastExecutedDue = $this->getLastExecutedDue($event);
        if ($lastExecutedDue === null) {
            return false; // 初回実行
        }

        try {
            $cron = CronExpression::factory($event->expression);
            $previousRunDate = $cron->getPreviousRunDate($now->toDateTime());
            $previousDue = Carbon::instance($previousRunDate);
        } catch (\Exception $e) {
            $this->logger->warning('[GracefulScheduleWorker] Invalid cron expression', [
                'expression' => $event->expression,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return $previousDue->greaterThan($lastExecutedDue);
    }

    /**
     * {@inheritdoc}
     */
    public function getLastExecutedDue(Event $event): ?Carbon
    {
        $key = $this->getLastExecutedKey($event);
        $timestamp = $this->cache->get($key);

        if ($timestamp === null || !is_numeric($timestamp)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $timestamp);
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, Carbon $dueAt): bool
    {
        $key = $this->getLockKey($event, $dueAt);
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            $lock = $store->lock($key, $this->lockTtl);
            if ($lock->get()) {
                $this->acquiredLocks[$key] = $lock;
                return true;
            }
            return false;
        }

        // フォールバック: has() + put()
        if ($this->cache->has($key)) {
            return false;
        }
        $this->cache->put($key, true, $this->lockTtl);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(Event $event, Carbon $dueAt): void
    {
        $key = $this->getLockKey($event, $dueAt);

        if (isset($this->acquiredLocks[$key])) {
            $this->acquiredLocks[$key]->release();
            unset($this->acquiredLocks[$key]);
            return;
        }

        // フォールバック
        $this->cache->forget($key);
    }

    /**
     * @param Event $event
     * @return string
     */
    private function getLastExecutedKey(Event $event): string
    {
        return self::PREFIX . 'last:' . $event->mutexName();
    }

    /**
     * @param Event $event
     * @param Carbon $dueAt
     * @return string
     */
    private function getLockKey(Event $event, Carbon $dueAt): string
    {
        return self::PREFIX . 'lock:' . $event->mutexName() . ':' . $dueAt->timestamp;
    }

    /**
     * @param Event $event
     * @return int
     */
    private function calculateTtl(Event $event): int
    {
        if ($event instanceof ClockAwareEvent && $event->getGracePeriod() !== null) {
            $gracePeriod = $event->getGracePeriod();
            $seconds = $this->dateIntervalToSeconds($gracePeriod);
            return $seconds * 2;
        }
        return 86400; // 24時間
    }

    /**
     * @param DateInterval $interval
     * @return int
     */
    private function dateIntervalToSeconds(DateInterval $interval): int
    {
        $days = $interval->days !== false ? $interval->days : 0;
        return ($days * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }
}
