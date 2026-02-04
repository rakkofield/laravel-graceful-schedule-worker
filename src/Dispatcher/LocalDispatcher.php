<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;

class LocalDispatcher implements ScheduleDispatcherInterface
{
    /**
     * @var string|null
     */
    private $basePath;

    /**
     * @param string|null $basePath プロセスの作業ディレクトリ（null の場合は現在のディレクトリ）
     */
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath;
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
     * @return DispatchResultInterface ディスパッチ結果
     */
    public function dispatchEvent(Event $event, Container $container): DispatchResultInterface
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

            return new StartedLocalDispatchResult($process, $identifier, $fullCommand);
        } catch (\Exception $e) {
            $error = get_class($e) . ': ' . $e->getMessage();

            return new FailedLocalDispatchResult($identifier, $event->command, $error, $e);
        }
    }
}
