<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

/**
 * Dispatcher の結果を型安全に扱うためのインターフェース
 *
 * 共通メタデータを定義し、各 Dispatcher 固有の情報は具象クラスで保持します。
 *
 * 成功時は StartedDispatchResultInterface、失敗時は FailedDispatchResultInterface を
 * 使用することで、型安全に結果を扱うことができます。
 */
interface DispatchResultInterface
{
    /**
     * イベントの識別子を取得（mutex name）
     *
     * @return string イベント識別子
     */
    public function getEventIdentifier(): string;

    /**
     * 実行コマンドを取得
     *
     * LocalDispatcher: シェル実行コマンド（出力リダイレクト含む）
     * StepFunctionsDispatcher: Event::command の値
     *
     * @return string 実行コマンド
     */
    public function getEventCommand(): string;

    /**
     * Dispatcher 種別を取得
     *
     * @return string Dispatcher 種別（'local' | 'stepfunctions' | 'tracking'）
     */
    public function getDispatcherType(): string;

    /**
     * ディスパッチ時刻を取得
     *
     * @return \DateTimeImmutable ディスパッチ時刻
     */
    public function getDispatchedAt(): \DateTimeImmutable;
}
