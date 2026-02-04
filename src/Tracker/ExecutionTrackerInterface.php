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
     * リカバリすべき取りこぼしがあれば、その実行予定時刻を返す
     *
     * 以下の条件をすべて満たす場合に missedDue を返す:
     * - 前回の実行予定時刻より後の実行予定が存在する（取りこぼしあり）
     * - grace period 内である（ClockAwareEvent の場合）
     *
     * 初回実行（実行記録なし）の場合は null を返す。
     * cron 式が不正な場合は例外を投げる。
     *
     * @param Event $event チェック対象のイベント
     * @param Carbon $now 現在時刻
     * @return Carbon|null リカバリすべき場合は missedDue、そうでなければ null
     * @throws \InvalidArgumentException cron 式が不正な場合
     */
    public function getMissedDueIfRecoverable(Event $event, Carbon $now): ?Carbon;

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
