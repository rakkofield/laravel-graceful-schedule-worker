<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;

class LocalDispatcher implements ScheduleDispatcherInterface
{
    /**
     * 単一イベントをディスパッチする
     *
     * Event をバックグラウンドプロセスとして実行します。
     * - beforeCallbacks を親プロセスで同期実行
     * - runInBackground を強制 true にして buildCommand() を呼び出し
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

            // 2. runInBackground を強制的に true にして buildCommand を呼ぶ
            //    これにより schedule:finish が含まれ、afterCallbacks が動作する
            $originalRunInBackground = $event->runInBackground;
            $event->runInBackground = true;
            $fullCommand = $event->buildCommand();
            $event->runInBackground = $originalRunInBackground;

            // 3. Process::start() でバックグラウンド実行
            $process = Process::fromShellCommandLine($fullCommand);
            $process->start();

            return LocalDispatchResult::success($process, $identifier, $fullCommand);
        } catch (\Throwable $e) {
            return LocalDispatchResult::failed($identifier, $event->command, get_class($e) . ': ' . $e->getMessage());
        }
    }
}
