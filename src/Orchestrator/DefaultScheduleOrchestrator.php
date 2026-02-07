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
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * デフォルトのスケジュールオーケストレーター
 *
 * Dispatcher パターンを使用してスケジュールされたイベントを実行します。
 *
 * 責務:
 * - スケジュール判定（毎分0秒のチェック）
 * - リカバリ検出（getMissedDueIfRecoverable）
 * - dueAt 決定
 *
 * ロック取得・実行記録・失敗ハンドリングは TrackingDispatcher が担当
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
     * @param ClockInterface $clock 時刻プロバイダ
     * @param ExecutionTrackerInterface $tracker 実行トラッカー（リカバリ検出に使用）
     * @param LoggerInterface $logger ロガー
     * @param SleeperInterface $sleeper スリーパー
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
        // Laravel の Application は Container を継承しているため、Container::getInstance() を使用
        $container = Container::getInstance();
        $lastExecutionStartedAt = $this->getCurrentTime()->modify('-10 minutes');

        // 起動時に一度だけ取りこぼしチェック
        $this->checkMissedExecutions($schedule, $container, Carbon::instance($this->getCurrentTime()));

        while ($shouldContinue()) {
            // スリープを挟んで CPU 負荷を軽減
            $this->sleeper->sleep();

            $now = $this->getCurrentTime();
            $currentMinute = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);

            // 毎分0秒に一度だけイベントをディスパッチ
            if (
                (int) $now->format('s') === 0 &&
                $currentMinute != $lastExecutionStartedAt
            ) {
                $lastExecutionStartedAt = $currentMinute;

                $this->evaluateAndDispatch($schedule, $app, $container, $now);
            }

            // 完了したプロセスをクリーンアップ
            $this->dispatcher->cleanup();
        }

        // 終了時に実行中のプロセスを停止
        $this->dispatcher->stopAll();

        return true;
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

        $this->logger->info('[GracefulScheduleWorker] Recovering missed event', [
            'event' => $event->mutexName(),
            'due' => $missedDueCarbon->toDateTimeString(),
        ]);

        // リカバリでは filtersPass() をチェックしない。
        // リカバリ時は clock が freeze されていないため、between()/unlessBetween() 等の
        // 時間ベースフィルタは現在時刻で評価される。過去の dueAt に対して現在時刻で
        // 評価すると誤った結果になる。
        $this->dispatcher->dispatchEvent($event, $container, $missedDue);
    }

    /**
     * due イベントを評価してディスパッチ
     *
     * ClockAwareSchedule の場合は evaluateAt() で時刻を固定し、
     * dueEvents() と filtersPass() が同一時刻で評価されることを保証する。
     * 通常の Schedule の場合は freeze せずにそのまま評価する。
     *
     * @param Schedule $schedule
     * @param Application $app
     * @param Container $container
     * @param \DateTimeImmutable $now
     * @return void
     */
    private function evaluateAndDispatch(
        Schedule $schedule,
        Application $app,
        Container $container,
        \DateTimeImmutable $now
    ): void {
        $doEvaluate = function () use ($schedule, $app, $container, $now) {
            /** @var array<Event> $events */
            $events = $schedule->dueEvents($app);
            $nowCarbon = Carbon::instance($now);

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
                $this->dispatcher->dispatchEvent($event, $container, $nowCarbon);
            }
        };

        if ($schedule instanceof ClockAwareSchedule) {
            $schedule->evaluateAt($now, $doEvaluate);
        } else {
            $doEvaluate();
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
}
