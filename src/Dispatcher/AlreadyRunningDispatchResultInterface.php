<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

/**
 * 既に実行中のタスクに対するディスパッチ結果を表すマーカーインターフェース
 *
 * Step Functions の ExecutionAlreadyExists など、
 * 重複実行を検出した場合に使用します。
 *
 * instanceof で既存実行判定に使用します。
 */
interface AlreadyRunningDispatchResultInterface extends DispatchResultInterface
{
}
