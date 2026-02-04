<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

/**
 * 成功したディスパッチ結果を表すインターフェース
 */
interface StartedDispatchResultInterface extends DispatchResultInterface
{
    /**
     * ディスパッチが開始されたかどうか
     *
     * @return true 常に true
     */
    public function isStarted(): bool;

    /**
     * エラーメッセージを取得
     *
     * @return null 成功時は常に null
     */
    public function getError(): ?string;
}
