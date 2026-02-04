<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

/**
 * 失敗したディスパッチ結果を表すインターフェース
 */
interface FailedDispatchResultInterface extends DispatchResultInterface
{
    /**
     * ディスパッチが開始されたかどうか
     *
     * @return false 常に false
     */
    public function isStarted(): bool;

    /**
     * エラーメッセージを取得
     *
     * @return string 失敗時は常にエラーメッセージを返す
     */
    public function getError(): ?string;
}
