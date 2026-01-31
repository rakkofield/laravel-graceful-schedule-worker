<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Console\GracefulScheduleWorkCommand;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class GracefulScheduleWorkerProvider extends ServiceProvider
{
    public function register()
    {
        // ClockInterface をシングルトンとして登録
        $this->app->singleton(ClockInterface::class, SystemClock::class);

        // ClockAwareSchedule をシングルトンとして登録
        $this->app->singleton(ClockAwareSchedule::class);

        // Schedule のエイリアスとして ClockAwareSchedule を登録（オプション）
        $this->app->extend(Schedule::class, function ($schedule, $app) {
            return $app->make(ClockAwareSchedule::class);
        });

        // LocalDispatcher を登録
        $this->app->singleton(LocalDispatcher::class);

        // CompositeDispatcher を ScheduleDispatcherInterface として登録
        $this->app->singleton(ScheduleDispatcherInterface::class, function ($app) {
            // Containerから設定を取得（デフォルト: 'local'）
            $defaultType = 'local';
            if ($app->bound('config')) {
                $defaultType = $app->make('config')->get('graceful-scheduler.dispatch', 'local');
            }

            return new CompositeDispatcher(
                [
                    'local' => $app->make(LocalDispatcher::class),
                    // 'stepfunctions' は Phase 4 で追加
                ],
                $defaultType
            );
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GracefulScheduleWorkCommand::class
            ]);

            // 設定ファイルのパブリッシュとマージ
            $configPath = __DIR__ . '/../../config/graceful-scheduler.php';
            $this->publishes([
                $configPath => config_path('graceful-scheduler.php'),
            ], 'config');

            $this->mergeConfigFrom($configPath, 'graceful-scheduler');
        }
    }
}
