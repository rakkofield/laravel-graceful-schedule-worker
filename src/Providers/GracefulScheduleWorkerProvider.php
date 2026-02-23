<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\Sleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporterInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Console\GracefulScheduleWorkCommand;
use RakkoInc\LaravelGracefulScheduleWorker\Console\LegacyExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\RunningProcessManager;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

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
        $this->registerDispatchers();
        $this->registerTrackerBindings();
        $this->registerOrchestrator();
        $this->registerExceptionReporter();
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

    /**
     * Register dispatcher bindings (LocalDispatcher, CompositeDispatcher, TrackingDispatcher).
     *
     * @return void
     */
    protected function registerDispatchers(): void
    {
        // Note: basePath() is available for Foundation\Application
        // When using Container only (e.g., tests), basePath is null
        // @phpstan-ignore function.alreadyNarrowedType (tests may use Container)
        $basePath = method_exists($this->app, 'basePath') ? $this->app->basePath() : null;
        $this->app->singleton(LocalDispatcher::class, function (Container $app) use ($basePath) {
            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $processManager = new RunningProcessManager($app, $logger, new Sleeper(10000), 10.0);

            return new LocalDispatcher($app, $basePath, $logger, $clock, $processManager);
        });

        $this->app->singleton(CompositeDispatcher::class, function (Container $app) {
            /** @var LocalDispatcher $localDispatcher */
            $localDispatcher = $app->make(LocalDispatcher::class);

            $dispatchers = [
                DispatcherType::LOCAL => $localDispatcher,
            ];

            if ($app->bound(StepFunctionsDispatcher::class) && $app->bound('config')) {
                /** @var ConfigRepository $sfConfig */
                $sfConfig = $app->make('config');
                /** @var string|null $sfArn */
                $sfArn = $sfConfig->get('graceful-scheduler.stepfunctions.state_machine_arn', '');
                $sfArn = (string) $sfArn;
                if ($sfArn !== '') {
                    /** @var StepFunctionsDispatcher $stepFunctionsDispatcher */
                    $stepFunctionsDispatcher = $app->make(StepFunctionsDispatcher::class);
                    $dispatchers[DispatcherType::STEP_FUNCTIONS] = $stepFunctionsDispatcher;
                }
            }

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            return new CompositeDispatcher($dispatchers, $logger);
        });

        // TrackingDispatcher wraps CompositeDispatcher and adds lock acquisition,
        // execution recording, and failure handling
        $this->app->singleton(ScheduleDispatcherInterface::class, function (Container $app) {
            /** @var CompositeDispatcher $compositeDispatcher */
            $compositeDispatcher = $app->make(CompositeDispatcher::class);
            /** @var ExecutionTrackerInterface $tracker */
            $tracker = $app->make(ExecutionTrackerInterface::class);

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            return new TrackingDispatcher($compositeDispatcher, $tracker, $logger, $clock);
        });
    }

    /**
     * Register ScheduleOrchestratorInterface binding.
     *
     * @return void
     */
    protected function registerOrchestrator(): void
    {
        $this->app->singleton(ScheduleOrchestratorInterface::class, function (Container $app) {
            /** @var ScheduleDispatcherInterface $dispatcher */
            $dispatcher = $app->make(ScheduleDispatcherInterface::class);
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);
            /** @var ExecutionTrackerInterface $tracker */
            $tracker = $app->make(ExecutionTrackerInterface::class);

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            return new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, $logger, new Sleeper(100000));
        });
    }

    /**
     * Register ExceptionReporterInterface binding.
     *
     * Laravel 6: ExceptionHandler::report(Exception), Laravel 7+: report(Throwable)
     *
     * @return void
     */
    protected function registerExceptionReporter(): void
    {
        $this->app->singleton(ExceptionReporterInterface::class, function (Container $app) {
            /** @var ExceptionHandler $handler */
            $handler = $app->make(ExceptionHandler::class);
            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            // Laravel 6: ExceptionHandler::report(Exception $e)
            // Laravel 7+: ExceptionHandler::report(Throwable $e)
            if (version_compare($this->app->version(), '7.0.0', '<')) {
                return new LegacyExceptionReporter($handler, $logger);
            }
            return new ExceptionReporter($handler, $logger);
        });
    }

    /**
     * Register ExecutionTracker related bindings.
     *
     * Registers CacheExecutionTracker or NullExecutionTracker based on tracker.enabled setting.
     *
     * @return void
     */
    protected function registerTrackerBindings(): void
    {
        $this->app->singleton(ExecutionTrackerInterface::class, function (Container $app) {
            if (!$app->bound('config')) {
                return new NullExecutionTracker();
            }

            /** @var ConfigRepository $config */
            $config = $app->make('config');

            if (!$config->get('graceful-scheduler.tracker.enabled', false)) {
                return new NullExecutionTracker();
            }

            /** @var string|null $storeName */
            $storeName = $config->get('graceful-scheduler.tracker.store');

            /** @var \Illuminate\Contracts\Cache\Factory $cacheFactory */
            $cacheFactory = $app->make('cache');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $cacheFactory->store($storeName);

            $store = $cache->getStore();
            if (!$store instanceof LockProvider) {
                throw new \RuntimeException(
                    'ExecutionTracker requires a cache driver that implements LockProvider (e.g., Redis, Memcached). ' .
                    'Current driver does not support distributed locking.'
                );
            }

            /** @var int|string $lockTtl */
            $lockTtl = $config->get('graceful-scheduler.tracker.lock_ttl', 3600);
            $lockTtl = (int) $lockTtl;

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            return new CacheExecutionTracker($cache, $store, $logger, $lockTtl);
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
