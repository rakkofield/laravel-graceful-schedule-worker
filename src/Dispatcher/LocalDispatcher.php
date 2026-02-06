<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    private $runningProcesses = [];

    /**
     * @param string|null $basePath プロセスの作業ディレクトリ（null の場合は現在のディレクトリ）
     * @param LoggerInterface|null $logger ロガー（null の場合は NullLogger）
     */
    public function __construct(?string $basePath = null, ?LoggerInterface $logger = null)
    {
        $this->basePath = $basePath;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 単一イベントをディスパッチする
     *
     * Event をバックグラウンドプロセスとして実行します。
     * - beforeCallbacks を親プロセスで同期実行
     * - runInBackground を true に設定して buildCommand() を呼び出し
     *   （これにより schedule:finish が含まれ、afterCallbacks が動作する）
     * - Process::start() でバックグラウンド実行
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
            // 1. beforeCallbacks を呼ぶ
            $event->callBeforeCallbacks($container);

            // 2. runInBackground を true に設定して buildCommand を呼ぶ
            //    これにより schedule:finish が含まれ、afterCallbacks が動作する
            //    （イベントは1回しかディスパッチされないため元に戻す必要はない）
            $event->runInBackground = true;
            $fullCommand = $event->buildCommand();

            // 3. Process::start() でバックグラウンド実行
            $process = Process::fromShellCommandline($fullCommand, $this->basePath);
            $process->start();

            $result = new StartedLocalDispatchResult($process, $identifier, $fullCommand);
            $this->runningProcesses[] = $result;

            return $result;
        } catch (\Exception $e) {
            $error = get_class($e) . ': ' . $e->getMessage();

            return new FailedLocalDispatchResult($identifier, $event->command, $error, $e);
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
        foreach ($this->runningProcesses as $result) {
            try {
                $process = $result->getProcess();
                if ($process->isRunning()) {
                    $process->stop();
                }
            } catch (\Exception $e) {
                // 1つのプロセスの停止失敗が他のプロセスの停止を阻害しないようにする
                $this->logger->warning('[GracefulScheduleWorker] Failed to stop process', [
                    'event' => $result->getEventIdentifier(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
        $this->runningProcesses = [];
    }
}
