<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

/**
 * 成功したディスパッチ結果を表すマーカーインターフェース
 *
 * instanceof で成功判定に使用します。
 */
interface StartedDispatchResultInterface extends DispatchResultInterface
{
}
