<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Console\GracefulScheduleWorkCommand;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;

class GracefulScheduleWorkerProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/graceful-scheduler.php',
            'graceful-scheduler'
        );

        $this->registerClock();
        $this->registerLogger();

        // Note: basePath()/version() are available for Foundation\Application
        // When using Container only (e.g., tests), basePath is null, version defaults to '0.0.0'
        // @phpstan-ignore function.alreadyNarrowedType (tests may use Container)
        $basePath = method_exists($this->app, 'basePath') ? $this->app->basePath() : null;
        // @phpstan-ignore function.alreadyNarrowedType (tests may use Container)
        $appVersion = method_exists($this->app, 'version') ? $this->app->version() : '0.0.0';

        (new DispatcherServiceRegistrar($this->app, $basePath))->register();
        (new TrackerServiceRegistrar($this->app))->register();
        (new OrchestratorServiceRegistrar($this->app, $appVersion))->register();
    }

    /**
     * Register ClockInterface binding.
     *
     * @return void
     */
    protected function registerClock(): void
    {
        $this->app->singleton(ClockInterface::class, SystemClock::class);
    }

    /**
     * Register PrefixedLogger as a singleton.
     *
     * @return void
     */
    protected function registerLogger(): void
    {
        $this->app->singleton('graceful-scheduler.logger', function (Container $app) {
            /** @var LoggerInterface $rawLogger */
            $rawLogger = $app->make('log');

            return new PrefixedLogger($rawLogger);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GracefulScheduleWorkCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../../config/graceful-scheduler.php' => $this->app->configPath('graceful-scheduler.php'),
            ], 'config');
        }
    }
}
