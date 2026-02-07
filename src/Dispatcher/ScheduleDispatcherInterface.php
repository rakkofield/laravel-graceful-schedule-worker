<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;

interface ScheduleDispatcherInterface
{
    /**
     * 単一イベントをディスパッチする
     *
     * @param Event $event 実行するスケジュールイベント
     * @param Container $container Laravel コンテナインスタンス
     * @param DateTimeInterface $dueAt 実行予定時刻
     * @return DispatchResultInterface ディスパッチ結果
     */
    public function dispatchEvent(
        Event $event,
        Container $container,
        DateTimeInterface $dueAt
    ): DispatchResultInterface;

    /**
     * 完了したプロセスをクリーンアップする
     *
     * @return void
     */
    public function cleanup(): void;

    /**
     * 全プロセスを停止する
     *
     * @return void
     */
    public function stopAll(): void;
}
