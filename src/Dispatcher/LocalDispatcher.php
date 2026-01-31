<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;

class LocalDispatcher implements ScheduleDispatcherInterface
{
    /**
     * 単一イベントをディスパッチする
     *
     * Event::run() を呼び出してローカル実行します。
     * Event::run() は例外をスローしないため、常に true を返します。
     *
     * @param Event $event 実行するスケジュールイベント
     * @param Container $container Laravel コンテナインスタンス
     * @return bool 実行が成功したかどうか（常に true）
     */
    public function dispatchEvent(Event $event, Container $container): bool
    {
        $event->run($container);
        return true;
    }
}
