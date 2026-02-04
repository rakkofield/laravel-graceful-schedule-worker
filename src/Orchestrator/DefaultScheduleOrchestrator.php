<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StartedLocalDispatchResult;
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

    /** @var ClockInterface */
    private $clock;

    /** @var ExecutionTrackerInterface */
    private $tracker;

    /** @var LoggerInterface */
    private $logger;

    /** @var array<StartedLocalDispatchResult> */
    private $runningProcesses = [];

    /** @var int スリープ時間（マイクロ秒） */
    private $sleepMicroseconds = 100000;

    /**
     * @param ScheduleDispatcherInterface $dispatcher
     * @param ClockInterface $clock 時刻プロバイダ
     * @param ExecutionTrackerInterface $tracker 実行トラッカー
     * @param LoggerInterface $logger ロガー
     * @param int $sleepMicroseconds スリープ時間（マイクロ秒）
     */
    public function __construct(
        ScheduleDispatcherInterface $dispatcher,
        ClockInterface $clock,
        ExecutionTrackerInterface $tracker,
        LoggerInterface $logger,
        int $sleepMicroseconds = 100000
    ) {
        $this->dispatcher = $dispatcher;
        $this->clock = $clock;
        $this->tracker = $tracker;
        $this->logger = $logger;
        $this->sleepMicroseconds = $sleepMicroseconds;
    }

    /**
     * {@inheritdoc}
     */
    public function run(Schedule $schedule, Application $app, callable $shouldContinue): bool
    {
        // Laravel の Application は Container を継承しているため、Container::getInstance() を使用
        $container = Container::getInstance();
        $lastExecutionStartedAt = $this->getCurrentTime()->modify('-10 minutes');

        // 起動時に一度だけ取りこぼしチェック
        $this->checkMissedExecutions($schedule, $container, Carbon::instance($this->getCurrentTime()));

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
        if (!$this->tracker->acquireLock($event, $now)) {
            $this->logger->debug('[GracefulScheduleWorker] Lock not acquired, skipping event', [
                'event' => $event->mutexName(),
            ]);
            return;
        }

        $result = $this->dispatcher->dispatchEvent($event, $container);

        // StartedLocalDispatchResult の場合はプロセスを追跡
        if ($result instanceof StartedLocalDispatchResult) {
            $this->tracker->markExecuted($event, $now);
            $this->runningProcesses[] = $result;
        } elseif ($result->isStarted()) {
            // StepFunctions などその他の成功ケース
            $this->tracker->markExecuted($event, $now);
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
        foreach ($schedule->events() as $event) {
            if (!$this->isRecoverableEvent($event)) {
                continue;
            }

            $missedDue = $this->tracker->getMissedDueIfRecoverable($event, $now);
            if ($missedDue === null) {
                continue;
            }

            $this->recoverMissedEvent($event, $container, $missedDue);
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
     * 取りこぼしイベントをリカバリ
     *
     * @param Event $event
     * @param Container $container
     * @param DateTimeInterface $missedDue
     * @return void
     */
    private function recoverMissedEvent(Event $event, Container $container, DateTimeInterface $missedDue): void
    {
        // ログ出力用に Carbon に変換
        $missedDueCarbon = $missedDue instanceof Carbon ? $missedDue : Carbon::instance($missedDue);

        if (!$this->tracker->acquireLock($event, $missedDue)) {
            $this->logger->debug('[GracefulScheduleWorker] Recovery lock not acquired, another worker is recovering', [
                'event' => $event->mutexName(),
                'missedDue' => $missedDueCarbon->toDateTimeString(),
            ]);
            return;
        }
        $this->logger->info('[GracefulScheduleWorker] Recovering missed event', [
            'event' => $event->mutexName(),
            'due' => $missedDueCarbon->toDateTimeString(),
        ]);

        $result = $this->dispatcher->dispatchEvent($event, $container);

        if ($result instanceof StartedLocalDispatchResult) {
            $this->tracker->markExecuted($event, $missedDue);
            $this->runningProcesses[] = $result;
        } elseif ($result->isStarted()) {
            $this->tracker->markExecuted($event, $missedDue);
        } else {
            $this->handleDispatchFailure($event, $result);
        }
    }

    /**
     * 現在時刻を取得
     *
     * @return \DateTimeImmutable
     */
    private function getCurrentTime(): \DateTimeImmutable
    {
        return $this->clock->now();
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
                function (StartedLocalDispatchResult $result) {
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
            if ($process->isRunning()) {
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
        $this->logger->error('[GracefulScheduleWorker] Failed to dispatch event', [
            'event' => $event->mutexName(),
            'dispatcher_type' => $result->getDispatcherType(),
            'error' => $result->getError(),
        ]);
    }
}
