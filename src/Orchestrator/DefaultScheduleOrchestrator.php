<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * デフォルトのスケジュールオーケストレーター
 *
 * Dispatcher パターンを使用してスケジュールされたイベントを実行します。
 */
class DefaultScheduleOrchestrator implements ScheduleOrchestratorInterface
{
    /** @var ScheduleDispatcherInterface */
    private $dispatcher;

    /** @var ClockInterface|null */
    private $clock;

    /** @var ExecutionTrackerInterface|null */
    private $tracker;

    /** @var LoggerInterface */
    private $logger;

    /** @var array<LocalDispatchResult> */
    private $runningProcesses = [];

    /** @var int スリープ時間（マイクロ秒） */
    private $sleepMicroseconds = 100000;

    /**
     * @param ScheduleDispatcherInterface $dispatcher
     * @param ClockInterface|null $clock テスト用に時刻を注入可能
     * @param ExecutionTrackerInterface|null $tracker 実行トラッカー
     * @param LoggerInterface|null $logger ロガー
     */
    public function __construct(
        ScheduleDispatcherInterface $dispatcher,
        ?ClockInterface $clock = null,
        ?ExecutionTrackerInterface $tracker = null,
        ?LoggerInterface $logger = null
    ) {
        $this->dispatcher = $dispatcher;
        $this->clock = $clock;
        $this->tracker = $tracker;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * スリープ時間を設定（テスト用）
     *
     * @param int $microseconds
     * @return void
     */
    public function setSleepMicroseconds(int $microseconds): void
    {
        $this->sleepMicroseconds = $microseconds;
    }

    /**
     * {@inheritdoc}
     */
    public function run(Schedule $schedule, Application $app, callable $shouldContinue): bool
    {
        // Laravel の Application は Container を継承しているため、Container::getInstance() を使用
        $container = Container::getInstance();
        $lastExecutionStartedAt = $this->getCurrentTime()->modify('-10 minutes');

        while ($shouldContinue()) {
            // スリープを挟んで CPU 負荷を軽減
            if ($this->sleepMicroseconds > 0) {
                usleep($this->sleepMicroseconds);
            }

            $now = $this->getCurrentTime();
            $currentMinute = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);

            // 毎分0秒に一度だけイベントをディスパッチ
            if (
                (int) $now->format('s') === 0 &&
                $currentMinute != $lastExecutionStartedAt
            ) {
                $lastExecutionStartedAt = $currentMinute;

                // due なイベントを取得してディスパッチ
                /** @var array<\Illuminate\Console\Scheduling\Event> $events */
                $events = $schedule->dueEvents($app);

                $nowCarbon = Carbon::instance($now);

                foreach ($events as $event) {
                    $this->dispatchEventWithTracking($event, $container, $nowCarbon);
                }

                // 取りこぼしチェック
                $this->checkMissedExecutions($schedule, $container, $nowCarbon);
            }

            // 完了したプロセスをクリーンアップ
            $this->cleanupCompletedProcesses();
        }

        // 終了時に実行中のプロセスを停止
        $this->stopRunningProcesses();

        return true;
    }

    /**
     * トラッキング付きでイベントをディスパッチ
     *
     * @param Event $event
     * @param Container $container
     * @param Carbon $now
     * @return void
     */
    private function dispatchEventWithTracking(Event $event, Container $container, Carbon $now): void
    {
        // Tracker が設定されている場合はロックを取得
        if ($this->tracker !== null) {
            if (!$this->tracker->acquireLock($event, $now)) {
                $this->logger->debug('[GracefulScheduleWorker] Lock not acquired, skipping event', [
                    'event' => $event->mutexName(),
                ]);
                return;
            }
        }

        $result = $this->dispatcher->dispatchEvent($event, $container);

        // ディスパッチ成功時の処理
        if ($result->isStarted()) {
            // Tracker が設定されている場合は実行を記録
            if ($this->tracker !== null) {
                $this->tracker->markExecuted($event, $now);
            }

            // LocalDispatchResult の場合はプロセスを追跡
            if ($result instanceof LocalDispatchResult) {
                $this->runningProcesses[] = $result;
            }
        } else {
            $this->handleDispatchFailure($event, $result);
        }
    }

    /**
     * 取りこぼしタスクのリカバリを実行
     *
     * @param Schedule $schedule
     * @param Container $container
     * @param Carbon $now
     * @return void
     */
    private function checkMissedExecutions(Schedule $schedule, Container $container, Carbon $now): void
    {
        $tracker = $this->tracker;
        if ($tracker === null) {
            return;
        }

        foreach ($schedule->events() as $event) {
            if (!$this->isRecoverableEvent($event)) {
                continue;
            }

            if (!$tracker->wasMissed($event, $now)) {
                continue;
            }

            if (!$this->isWithinGracePeriod($event, $now)) {
                $this->logger->warning('[GracefulScheduleWorker] Skipping missed event: grace period exceeded', [
                    'event' => $event->mutexName(),
                ]);
                continue;
            }

            $this->recoverMissedEvent($event, $container, $now);
        }
    }

    /**
     * イベントがリカバリ可能かどうかをチェック
     *
     * @param Event $event
     * @return bool
     */
    private function isRecoverableEvent(Event $event): bool
    {
        return $event instanceof ClockAwareEvent && $event->isRecoverable();
    }

    /**
     * grace period 内かどうかをチェック
     *
     * @param Event $event
     * @param Carbon $now
     * @return bool
     */
    private function isWithinGracePeriod(Event $event, Carbon $now): bool
    {
        if (!$event instanceof ClockAwareEvent) {
            return false;
        }

        $gracePeriod = $event->getGracePeriod();
        if ($gracePeriod === null) {
            return true; // 無制限
        }

        if ($this->tracker === null) {
            return true;
        }

        $lastExecutedDue = $this->tracker->getLastExecutedDue($event);
        if ($lastExecutedDue === null) {
            return true; // 初回
        }

        $deadline = $lastExecutedDue->copy()->add($gracePeriod);
        return $now->lessThanOrEqualTo($deadline);
    }

    /**
     * 取りこぼしイベントをリカバリ
     *
     * @param Event $event
     * @param Container $container
     * @param Carbon $now
     * @return void
     */
    private function recoverMissedEvent(Event $event, Container $container, Carbon $now): void
    {
        $missedDue = $this->getMissedDue($event, $now);
        if ($missedDue === null) {
            return;
        }

        if ($this->tracker === null) {
            return;
        }

        if (!$this->tracker->acquireLock($event, $missedDue)) {
            return; // 他の Worker がリカバリ中
        }

        $this->logger->info('[GracefulScheduleWorker] Recovering missed event', [
            'event' => $event->mutexName(),
            'due' => $missedDue->toDateTimeString(),
        ]);

        $result = $this->dispatcher->dispatchEvent($event, $container);

        if ($result->isStarted()) {
            $this->tracker->markExecuted($event, $missedDue);

            if ($result instanceof LocalDispatchResult) {
                $this->runningProcesses[] = $result;
            }
        }
    }

    /**
     * 取りこぼした実行予定時刻を取得
     *
     * @param Event $event
     * @param Carbon $now
     * @return Carbon|null
     */
    private function getMissedDue(Event $event, Carbon $now): ?Carbon
    {
        try {
            $cron = CronExpression::factory($event->expression);
            $previousRunDate = $cron->getPreviousRunDate($now->toDateTime());
            return Carbon::instance($previousRunDate);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 現在時刻を取得
     *
     * @return \DateTimeImmutable
     */
    private function getCurrentTime(): \DateTimeImmutable
    {
        if ($this->clock !== null) {
            return $this->clock->now();
        }
        return new \DateTimeImmutable();
    }

    /**
     * 完了したプロセスを配列から削除
     *
     * @return void
     */
    private function cleanupCompletedProcesses(): void
    {
        $this->runningProcesses = array_values(
            array_filter(
                $this->runningProcesses,
                function (LocalDispatchResult $result) {
                    return $result->isRunning();
                }
            )
        );
    }

    /**
     * 実行中のプロセスを停止
     *
     * @return void
     */
    private function stopRunningProcesses(): void
    {
        foreach ($this->runningProcesses as $result) {
            $process = $result->getProcess();
            if ($process !== null && $process->isRunning()) {
                $process->stop();
            }
        }
        $this->runningProcesses = [];
    }

    /**
     * ディスパッチ失敗時のハンドリング
     *
     * @param \Illuminate\Console\Scheduling\Event $event
     * @param DispatchResultInterface $result
     * @return void
     */
    private function handleDispatchFailure(
        \Illuminate\Console\Scheduling\Event $event,
        DispatchResultInterface $result
    ): void {
        error_log(sprintf(
            '[GracefulScheduleWorker] Dispatch failed for event "%s": %s',
            $event->mutexName(),
            $result->getError() ?? 'Unknown error'
        ));
    }
}
