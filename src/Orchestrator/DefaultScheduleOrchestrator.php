<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;

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

    /** @var array<LocalDispatchResult> */
    private $runningProcesses = [];

    /** @var int スリープ時間（マイクロ秒） */
    private $sleepMicroseconds = 100000;

    /**
     * @param ScheduleDispatcherInterface $dispatcher
     * @param ClockInterface|null $clock テスト用に時刻を注入可能
     */
    public function __construct(ScheduleDispatcherInterface $dispatcher, ?ClockInterface $clock = null)
    {
        $this->dispatcher = $dispatcher;
        $this->clock = $clock;
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

                foreach ($events as $event) {
                    $result = $this->dispatcher->dispatchEvent($event, $container);

                    // LocalDispatchResult の場合はプロセスを追跡
                    if ($result instanceof LocalDispatchResult && $result->isStarted()) {
                        $this->runningProcesses[] = $result;
                    }
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
}
