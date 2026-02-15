<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Aws\Sfn\SfnClient;
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
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
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
        $this->registerDispatchers();
        $this->registerStepFunctionsBindings();
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
            /** @var LoggerInterface $rawLogger */
            $rawLogger = $app->make('log');
            $logger = new PrefixedLogger($rawLogger);

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            return new LocalDispatcher($app, $basePath, $logger, new Sleeper(10000), $clock);
        });

        $this->app->singleton(CompositeDispatcher::class, function (Container $app) {
            /** @var LocalDispatcher $localDispatcher */
            $localDispatcher = $app->make(LocalDispatcher::class);

            $dispatchers = [
                DispatcherType::LOCAL => $localDispatcher,
            ];

            // Add StepFunctionsDispatcher if available and ARN is configured
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

            /** @var LoggerInterface $rawLogger */
            $rawLogger = $app->make('log');
            $logger = new PrefixedLogger($rawLogger);

            return new CompositeDispatcher($dispatchers, $logger);
        });

        // TrackingDispatcher wraps CompositeDispatcher and adds lock acquisition,
        // execution recording, and failure handling
        $this->app->singleton(ScheduleDispatcherInterface::class, function (Container $app) {
            /** @var CompositeDispatcher $compositeDispatcher */
            $compositeDispatcher = $app->make(CompositeDispatcher::class);
            /** @var ExecutionTrackerInterface $tracker */
            $tracker = $app->make(ExecutionTrackerInterface::class);

            /** @var LoggerInterface $rawLogger */
            $rawLogger = $app->make('log');
            $logger = new PrefixedLogger($rawLogger);

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

            /** @var LoggerInterface $rawLogger */
            $rawLogger = $app->make('log');
            $logger = new PrefixedLogger($rawLogger);

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
            /** @var LoggerInterface $rawLogger */
            $rawLogger = $app->make('log');
            $logger = new PrefixedLogger($rawLogger);

            if ($this->isLegacyExceptionHandler()) {
                return new LegacyExceptionReporter($handler, $logger);
            }
            return new ExceptionReporter($handler, $logger);
        });
    }

    /**
     * Detect whether the current Laravel version uses legacy ExceptionHandler (Laravel 6).
     *
     * Laravel 6: ExceptionHandler::report(Exception $e)
     * Laravel 7+: ExceptionHandler::report(Throwable $e)
     *
     * @return bool
     */
    protected function isLegacyExceptionHandler(): bool
    {
        return version_compare($this->app->version(), '7.0.0', '<');
    }

    /**
     * Register Step Functions related bindings.
     *
     * Only registered when the AWS SDK is installed.
     *
     * @return void
     */
    protected function registerStepFunctionsBindings(): void
    {
        // Skip if AWS SDK is not installed
        if (!class_exists(SfnClient::class)) {
            return;
        }

        // Register StepFunctionsClientInterface
        $this->app->singleton(StepFunctionsClientInterface::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var array{region?: string, version?: string, credentials?: array{key?: string, secret?: string}, endpoint?: string} $sfConfig */
            $sfConfig = $config->get('graceful-scheduler.stepfunctions', []);

            $clientConfig = [
                'region' => $sfConfig['region'] ?? 'ap-northeast-1',
                'version' => $sfConfig['version'] ?? 'latest',
            ];

            // Only add credentials if configured
            if (!empty($sfConfig['credentials']['key']) && !empty($sfConfig['credentials']['secret'])) {
                $clientConfig['credentials'] = [
                    'key' => $sfConfig['credentials']['key'],
                    'secret' => $sfConfig['credentials']['secret'],
                ];
            }

            // If endpoint is configured (for test environments)
            if (isset($sfConfig['endpoint'])) {
                $clientConfig['endpoint'] = $sfConfig['endpoint'];
            }

            $client = new SfnClient($clientConfig);

            return new AwsSfnClientAdapter($client);
        });

        // Register ExecutionNameGeneratorInterface
        $this->app->singleton(ExecutionNameGeneratorInterface::class, ExecutionNameGenerator::class);

        // Register StepFunctionsDispatcher
        $this->app->singleton(StepFunctionsDispatcher::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var string $stateMachineArn */
            $stateMachineArn = $config->get('graceful-scheduler.stepfunctions.state_machine_arn', '');

            /** @var StepFunctionsClientInterface $client */
            $client = $app->make(StepFunctionsClientInterface::class);

            /** @var ExecutionNameGeneratorInterface $nameGenerator */
            $nameGenerator = $app->make(ExecutionNameGeneratorInterface::class);

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            return new StepFunctionsDispatcher($client, $stateMachineArn, $nameGenerator, $clock);
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
            // Use NullExecutionTracker if config is not registered
            if (!$app->bound('config')) {
                return new NullExecutionTracker();
            }

            /** @var ConfigRepository $config */
            $config = $app->make('config');

            // Use NullExecutionTracker if tracker is disabled
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

            /** @var LoggerInterface $rawLogger */
            $rawLogger = $app->make('log');
            $logger = new PrefixedLogger($rawLogger);

            return new CacheExecutionTracker($cache, $store, $logger, $lockTtl);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GracefulScheduleWorkCommand::class,
            ]);

            // Publish config file
            $this->publishes([
                __DIR__ . '/../../config/graceful-scheduler.php' => $this->app->configPath('graceful-scheduler.php'),
            ], 'config');
        }
    }
}
