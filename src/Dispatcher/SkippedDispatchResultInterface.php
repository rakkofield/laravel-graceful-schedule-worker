<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

/**
 * スキップされたディスパッチ結果を表すマーカーインターフェース
 *
 * ロック取得失敗など、ディスパッチがスキップされた場合に使用します。
 *
 * instanceof でスキップ判定に使用します。
 */
interface SkippedDispatchResultInterface extends DispatchResultInterface
{
    /**
     * スキップ理由を取得
     *
     * @return string スキップ理由（'lock_not_acquired' など）
     */
    public function getReason(): string;
}
