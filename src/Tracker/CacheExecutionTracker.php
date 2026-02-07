<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Cron\CronExpression;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Cache を使用した ExecutionTracker 実装
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
     * @param int $lockTtl ロックの TTL（秒）
     * @throws InvalidArgumentException lockTtl が正の整数でない場合
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
    public function markExecuted(Event $event, DateTimeInterface $dueAt): void
    {
        $key = $this->getLastExecutedKey($event);
        $ttl = $this->calculateTtl($event);
        $this->cache->put($key, $dueAt->getTimestamp(), $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function getMissedDueIfRecoverable(Event $event, DateTimeInterface $now): ?DateTimeInterface
    {
        $lastExecutedDue = $this->getLastExecutedDue($event);
        if ($lastExecutedDue === null) {
            return null; // 初回実行は取りこぼしなし
        }

        // cron 式から前回の実行予定時刻を計算（例外はそのまま伝播）
        try {
            $cron = new CronExpression($event->expression);
            $previousRunDate = $cron->getPreviousRunDate($now);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid cron expression: %s', $event->expression),
                0,
                $e
            );
        }
        $missedDue = DateTimeImmutable::createFromMutable($previousRunDate);

        // 取りこぼしチェック（タイムスタンプで比較）
        if ($missedDue->getTimestamp() <= $lastExecutedDue->getTimestamp()) {
            return null; // 取りこぼしなし
        }

        // grace period チェック（ClockAwareEvent の場合のみ）
        if ($event instanceof ClockAwareEvent) {
            $gracePeriod = $event->getGracePeriod();
            if ($gracePeriod !== null) {
                $deadline = $lastExecutedDue->add($gracePeriod);
                if ($now->getTimestamp() > $deadline->getTimestamp()) {
                    $this->logger->warning('[GracefulScheduleWorker] Skipping missed event: grace period exceeded', [
                        'event' => $event->mutexName(),
                        'missedDue' => $missedDue->format('Y-m-d H:i:s'),
                        'deadline' => $deadline->format('Y-m-d H:i:s'),
                    ]);
                    return null; // grace period 超過
                }
            }
        }

        return $missedDue;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(Event $event, DateTimeInterface $dueAt): bool
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
    public function releaseLock(Event $event, DateTimeInterface $dueAt): void
    {
        $key = $this->getLockKey($event, $dueAt);

        if (isset($this->acquiredLocks[$key])) {
            $this->acquiredLocks[$key]->release();
            unset($this->acquiredLocks[$key]);
        }
    }

    /**
     * 最後に実行された予定時刻を取得する
     *
     * @param Event $event 対象イベント
     * @return DateTimeImmutable|null 最後の実行予定時刻（未実行なら null）
     */
    private function getLastExecutedDue(Event $event): ?DateTimeImmutable
    {
        $key = $this->getLastExecutedKey($event);
        $timestamp = $this->cache->get($key);

        if ($timestamp === null || !is_numeric($timestamp)) {
            return null;
        }

        return new DateTimeImmutable('@' . (int) $timestamp);
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
     * @param DateTimeInterface $dueAt
     * @return string
     */
    private function getLockKey(Event $event, DateTimeInterface $dueAt): string
    {
        return self::PREFIX . 'lock:' . $event->mutexName() . ':' . $dueAt->getTimestamp();
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
        return self::DEFAULT_TTL_SECONDS;
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
