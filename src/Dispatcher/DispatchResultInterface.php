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
     * ディスパッチが開始されたかどうか
     *
     * @return bool 開始された場合は true
     */
    public function isStarted(): bool;

    /**
     * エラーメッセージを取得
     *
     * @return string|null エラーメッセージ（成功時は null）
     */
    public function getError(): ?string;

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
     * @return string Dispatcher 種別（'local' | 'stepfunctions'）
     */
    public function getDispatcherType(): string;

    /**
     * ディスパッチ時刻を取得
     *
     * @return \DateTimeImmutable ディスパッチ時刻
     */
    public function getDispatchedAt(): \DateTimeImmutable;

    /**
     * 発生した例外を取得
     *
     * @return \Throwable|null 例外（成功時は null）
     */
    public function getException(): ?\Throwable;
}
