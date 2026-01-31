<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;

interface ScheduleOrchestratorInterface
{
    /**
     * スケジュールされたタスクを調整・実行する
     *
     * @param Schedule $schedule Laravel のスケジュールオブジェクト
     * @param Application $app Laravel アプリケーションインスタンス
     * @param callable $shouldContinue 実行継続の判定関数
     * @return bool 実行が成功したかどうか
     */
    public function run(Schedule $schedule, Application $app, callable $shouldContinue): bool;
}
