<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;

interface ScheduleDispatcherInterface
{
    /**
     * 単一イベントをディスパッチする
     *
     * @param Event $event 実行するスケジュールイベント
     * @param Container $container Laravel コンテナインスタンス
     * @return bool 実行が成功したかどうか
     */
    public function dispatchEvent(Event $event, Container $container): bool;
}
