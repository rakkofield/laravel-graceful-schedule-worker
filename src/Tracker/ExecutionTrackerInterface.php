<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;

/**
 * 実行トラッキングの契約を定義するインターフェース
 *
 * At-least-once セマンティックをサポートするため、タスクの実行記録と
 * 取りこぼし検出を行う。
 */
interface ExecutionTrackerInterface
{
    /**
     * タスクの実行を記録する
     *
     * @param Event $event 実行されたイベント
     * @param Carbon $dueAt 実行予定時刻
     */
    public function markExecuted(Event $event, Carbon $dueAt): void;

    /**
     * タスクが取りこぼされたかどうかを判定する
     *
     * cron 式から前回の実行予定時刻を計算し、最後に実行された予定時刻と比較する。
     * 前回の実行予定時刻が最後の実行記録より後であれば、取りこぼしがある。
     *
     * @param Event $event チェック対象のイベント
     * @param Carbon $now 現在時刻
     * @return bool 取りこぼしがあれば true
     */
    public function wasMissed(Event $event, Carbon $now): bool;

    /**
     * 最後に実行された予定時刻を取得する
     *
     * @param Event $event 対象イベント
     * @return Carbon|null 最後の実行予定時刻（未実行なら null）
     */
    public function getLastExecutedDue(Event $event): ?Carbon;

    /**
     * 指定時刻に対するロックを取得する
     *
     * 複数 Worker が同じタスクを重複実行しないよう、排他ロックを取得する。
     *
     * @param Event $event 対象イベント
     * @param Carbon $dueAt 実行予定時刻
     * @return bool ロック取得成功なら true
     */
    public function acquireLock(Event $event, Carbon $dueAt): bool;

    /**
     * 指定時刻に対するロックを解放する
     *
     * @param Event $event 対象イベント
     * @param Carbon $dueAt 実行予定時刻
     */
    public function releaseLock(Event $event, Carbon $dueAt): void;
}
