<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;

interface ExecutionNameGeneratorInterface
{
    /**
     * Event と実行予定時刻から Execution Name を生成
     *
     * @param Event $event スケジュールイベント
     * @param DateTimeInterface $dueAt 実行予定時刻
     * @return string Execution Name（最大80文字、使用可能文字: a-z, A-Z, 0-9, -, _）
     */
    public function generate(Event $event, DateTimeInterface $dueAt): string;
}
