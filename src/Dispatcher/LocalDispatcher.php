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
     * Event の command を Process::start() でバックグラウンド実行します。
     *
     * @param Event $event 実行するスケジュールイベント
     * @param Container $container Laravel コンテナインスタンス
     * @return DispatchResultInterface ディスパッチ結果
     */
    public function dispatchEvent(Event $event, Container $container): DispatchResultInterface
    {
        $command = $event->command;
        $identifier = $event->mutexName();

        try {
            $process = Process::fromShellCommandLine($command);
            $process->start();

            return LocalDispatchResult::success($process, $identifier, $command);
        } catch (\Throwable $e) {
            return LocalDispatchResult::failed($identifier, $command, get_class($e) . ': ' . $e->getMessage());
        }
    }
}
