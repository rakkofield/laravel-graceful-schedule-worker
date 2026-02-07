<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

/**
 * 失敗したディスパッチ結果を表すインターフェース
 */
interface FailedDispatchResultInterface extends DispatchResultInterface
{
    /**
     * エラーメッセージを取得
     *
     * @return string エラーメッセージ
     */
    public function getError(): string;

    /**
     * 発生した例外を取得
     *
     * @return \Throwable|null 例外
     */
    public function getException(): ?\Throwable;
}
