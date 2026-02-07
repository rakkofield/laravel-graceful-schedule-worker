<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use Symfony\Component\Process\Process;

class LocalDispatcher implements ScheduleDispatcherInterface
{
    /**
     * @var string|null
     */
    private $basePath;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array<StartedLocalDispatchResult>
     */
    protected $runningProcesses = [];

    /**
     * @var SleeperInterface
     */
    private $sleeper;

    /**
     * @var float
     */
    private $stopTimeout;

    /**
     * @param string|null $basePath プロセスの作業ディレクトリ（null の場合は現在のディレクトリ）
     * @param LoggerInterface $logger ロガー
     * @param SleeperInterface $sleeper スリーパー（stopAll のポーリング用）
     * @param float $stopTimeout stopAll() での SIGTERM→SIGKILL 待機タイムアウト（秒）
     */
    public function __construct(
        ?string $basePath,
        LoggerInterface $logger,
        SleeperInterface $sleeper,
        float $stopTimeout = 10.0
    ) {
        $this->basePath = $basePath;
        $this->logger = $logger;
        $this->sleeper = $sleeper;
        $this->stopTimeout = $stopTimeout;
    }

    /**
     * 単一イベントをディスパッチする
     *
     * Event の runInBackground 設定を尊重し、適切な実行パスを選択します。
     * - beforeCallbacks を親プロセスで同期実行
     * - runInBackground = true: buildCommand() から & を除去し Process::start() で非同期実行
     *   （schedule:finish が含まれ、afterCallbacks は子プロセスが実行する）
     *   ClockAwareEvent の場合は buildProcessCommand() を使用する
     * - runInBackground = false: buildCommand() で同期実行し、afterCallbacks を直接呼ぶ
     *
     * @param Event $event 実行するスケジュールイベント
     * @param Container $container Laravel コンテナインスタンス
     * @param DateTimeInterface $dueAt 実行予定時刻（LocalDispatcher では未使用）
     * @return DispatchResultInterface ディスパッチ結果
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        $identifier = $event->mutexName();

        try {
            $event->callBeforeCallbacks($container);

            if ($event->runInBackground) {
                // Background: schedule:finish を含むコマンドを生成し、& を除去して非同期実行
                if ($event instanceof ClockAwareEvent) {
                    $fullCommand = $event->buildProcessCommand();
                } else {
                    $fullCommand = $event->buildCommand();
                    // schedule:finish を含まないコマンドから末尾の & を除去
                    $fullCommand = preg_replace('/\s+&\s*$/', '', $fullCommand) ?? $fullCommand;
                }
                $process = Process::fromShellCommandline($fullCommand, $this->basePath);
                $process->start();

                $result = new StartedLocalDispatchResult($process, $identifier, $fullCommand, new DateTimeImmutable());
                $this->runningProcesses[] = $result;
                return $result;
            }

            // Foreground: schedule:finish を含まないコマンドを同期実行し、afterCallbacks を直接呼ぶ
            $fullCommand = $event->buildCommand();
            $process = Process::fromShellCommandline($fullCommand, $this->basePath);
            $process->setTimeout(null);
            $process->run();

            try {
                $event->callAfterCallbacksWithExitCode($container, (int) $process->getExitCode());
            } catch (\Exception $e) {
                $this->logger->warning('[GracefulScheduleWorker] afterCallback failed', [
                    'event' => $identifier,
                    'exitCode' => $process->getExitCode(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }

            return new StartedLocalDispatchResult($process, $identifier, $fullCommand, new DateTimeImmutable());
        } catch (\Exception $e) {
            $error = get_class($e) . ': ' . $e->getMessage();

            return new FailedLocalDispatchResult($identifier, $event->command, $error, $e, new DateTimeImmutable());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
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
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        // Phase 1: 全 running プロセスに SIGTERM を一斉送信
        foreach ($this->runningProcesses as $result) {
            try {
                $process = $result->getProcess();
                if ($process->isRunning()) {
                    $process->signal(SIGTERM);
                }
            } catch (\Exception $e) {
                $this->logger->warning('[GracefulScheduleWorker] Failed to send SIGTERM', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        // Phase 2: タイムアウトまでポーリングで全プロセスの終了を待機
        $deadline = microtime(true) + $this->stopTimeout;
        while (microtime(true) < $deadline) {
            $allStopped = true;
            foreach ($this->runningProcesses as $result) {
                if ($result->isRunning()) {
                    $allStopped = false;
                    break;
                }
            }
            if ($allStopped) {
                break;
            }
            $this->sleeper->sleep();
        }

        // Phase 3: まだ running なプロセスに SIGKILL を送信
        foreach ($this->runningProcesses as $result) {
            try {
                $process = $result->getProcess();
                if ($process->isRunning()) {
                    $process->signal(SIGKILL);
                }
            } catch (\Exception $e) {
                $this->logger->warning('[GracefulScheduleWorker] Failed to send SIGKILL', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $this->runningProcesses = [];
    }
}
