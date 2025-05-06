<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Support\ServiceProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Console\GracefulScheduleWorkCommand;

class GracefulScheduleWorkerProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GracefulScheduleWorkCommand::class
            ]);
        }
    }
}
