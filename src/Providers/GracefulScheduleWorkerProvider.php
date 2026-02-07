<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Aws\Sfn\SfnClient;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\Sleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Console\GracefulScheduleWorkCommand;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

class GracefulScheduleWorkerProvider extends ServiceProvider
{
    public function register(): void
    {
        // 設定ファイルをマージ（register時に設定が必要なため）
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/graceful-scheduler.php',
            'graceful-scheduler'
        );

        // ClockInterface をシングルトンとして登録
        $this->app->singleton(ClockInterface::class, SystemClock::class);

        // ClockAwareSchedule をシングルトンとして登録
        // 利用側が必要に応じて Schedule の代わりに使用可能
        $this->app->singleton(ClockAwareSchedule::class);

        // LocalDispatcher を登録
        // Note: Foundation\Application の場合は basePath() が利用可能
        // テスト等で Container のみの場合は basePath は null
        // @phpstan-ignore function.alreadyNarrowedType (テストでは Container を使うため)
        $basePath = method_exists($this->app, 'basePath') ? $this->app->basePath() : null;
        $this->app->singleton(LocalDispatcher::class, function (Container $app) use ($basePath) {
            // Logger を取得（Laravel の log サービスから、なければ NullLogger）
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $app->bound('log') ? $app->make('log') : new NullLogger();

            return new LocalDispatcher($basePath, $logger, new Sleeper(10000));
        });

        // StepFunctions 関連のバインディング（AWS SDK がインストールされている場合のみ）
        $this->registerStepFunctionsBindings();

        // ExecutionTrackerInterface を登録（常に登録、設定に応じて実装を切り替え）
        $this->registerTrackerBindings();

        // CompositeDispatcher を登録（内部で使用）
        $this->app->singleton(CompositeDispatcher::class, function (Container $app) {
            // Containerから設定を取得（デフォルト: 'local'）
            $defaultType = 'local';
            if ($app->bound('config')) {
                /** @var ConfigRepository $config */
                $config = $app->make('config');
                /** @var string $defaultType */
                $defaultType = $config->get('graceful-scheduler.dispatch', 'local');
            }

            /** @var LocalDispatcher $localDispatcher */
            $localDispatcher = $app->make(LocalDispatcher::class);

            $dispatchers = [
                'local' => $localDispatcher,
            ];

            // StepFunctionsDispatcher が利用可能な場合は追加
            if ($app->bound(StepFunctionsDispatcher::class)) {
                /** @var StepFunctionsDispatcher $stepFunctionsDispatcher */
                $stepFunctionsDispatcher = $app->make(StepFunctionsDispatcher::class);
                $dispatchers['stepfunctions'] = $stepFunctionsDispatcher;
            }

            // Logger を取得（Laravel の log サービスから、なければ NullLogger）
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $app->bound('log') ? $app->make('log') : new NullLogger();

            return new CompositeDispatcher($dispatchers, $defaultType, $logger);
        });

        // TrackingDispatcher を ScheduleDispatcherInterface として登録
        // CompositeDispatcher をラップし、ロック取得・実行記録・失敗ハンドリングを追加
        $this->app->singleton(ScheduleDispatcherInterface::class, function (Container $app) {
            /** @var CompositeDispatcher $compositeDispatcher */
            $compositeDispatcher = $app->make(CompositeDispatcher::class);
            /** @var ExecutionTrackerInterface $tracker */
            $tracker = $app->make(ExecutionTrackerInterface::class);

            // Logger を取得（Laravel の log サービスから、なければ NullLogger）
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $app->bound('log') ? $app->make('log') : new NullLogger();

            return new TrackingDispatcher($compositeDispatcher, $tracker, $logger);
        });

        // ScheduleOrchestratorInterface を登録
        $this->app->singleton(ScheduleOrchestratorInterface::class, function (Container $app) {
            /** @var ScheduleDispatcherInterface $dispatcher */
            $dispatcher = $app->make(ScheduleDispatcherInterface::class);
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);
            /** @var ExecutionTrackerInterface $tracker */
            $tracker = $app->make(ExecutionTrackerInterface::class);

            // Logger を取得（Laravel の log サービスから、なければ NullLogger）
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $app->bound('log') ? $app->make('log') : new NullLogger();

            return new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, $logger, new Sleeper(100000));
        });
    }

    /**
     * StepFunctions 関連のバインディングを登録
     *
     * AWS SDK がインストールされている場合のみ登録されます。
     *
     * @return void
     */
    protected function registerStepFunctionsBindings(): void
    {
        // AWS SDK がインストールされていない場合はスキップ
        if (!class_exists(SfnClient::class)) {
            return;
        }

        // StepFunctionsClientInterface を登録
        $this->app->singleton(StepFunctionsClientInterface::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var array{region?: string, version?: string, credentials?: array{key?: string, secret?: string}, endpoint?: string} $sfConfig */
            $sfConfig = $config->get('graceful-scheduler.stepfunctions', []);

            $clientConfig = [
                'region' => $sfConfig['region'] ?? 'ap-northeast-1',
                'version' => $sfConfig['version'] ?? 'latest',
            ];

            // credentials が設定されている場合のみ追加
            if (!empty($sfConfig['credentials']['key']) && !empty($sfConfig['credentials']['secret'])) {
                $clientConfig['credentials'] = [
                    'key' => $sfConfig['credentials']['key'],
                    'secret' => $sfConfig['credentials']['secret'],
                ];
            }

            // endpoint が設定されている場合（テスト環境用）
            if (isset($sfConfig['endpoint'])) {
                $clientConfig['endpoint'] = $sfConfig['endpoint'];
            }

            $client = new SfnClient($clientConfig);

            return new AwsSfnClientAdapter($client);
        });

        // ExecutionNameGeneratorInterface を登録
        $this->app->singleton(ExecutionNameGeneratorInterface::class, ExecutionNameGenerator::class);

        // StepFunctionsDispatcher を登録
        $this->app->singleton(StepFunctionsDispatcher::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var string $stateMachineArn */
            $stateMachineArn = $config->get('graceful-scheduler.stepfunctions.state_machine_arn', '');

            /** @var StepFunctionsClientInterface $client */
            $client = $app->make(StepFunctionsClientInterface::class);

            /** @var ExecutionNameGeneratorInterface $nameGenerator */
            $nameGenerator = $app->make(ExecutionNameGeneratorInterface::class);

            return new StepFunctionsDispatcher($client, $stateMachineArn, $nameGenerator);
        });
    }

    /**
     * ExecutionTracker 関連のバインディングを登録
     *
     * tracker.enabled に応じて CacheExecutionTracker または NullExecutionTracker を登録します。
     *
     * @return void
     */
    protected function registerTrackerBindings(): void
    {
        $this->app->singleton(ExecutionTrackerInterface::class, function (Container $app) {
            // config が登録されていない場合は NullExecutionTracker
            if (!$app->bound('config')) {
                return new NullExecutionTracker();
            }

            /** @var ConfigRepository $config */
            $config = $app->make('config');

            // tracker が無効の場合は NullExecutionTracker
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

            /** @var int $lockTtl */
            $lockTtl = $config->get('graceful-scheduler.tracker.lock_ttl', 3600);

            // Logger を取得（Laravel の log サービスから、なければ NullLogger）
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $app->bound('log') ? $app->make('log') : new NullLogger();

            return new CacheExecutionTracker($cache, $store, $logger, $lockTtl);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GracefulScheduleWorkCommand::class,
            ]);

            // 設定ファイルのパブリッシュ
            $this->publishes([
                __DIR__ . '/../../config/graceful-scheduler.php' => $this->app->configPath('graceful-scheduler.php'),
            ], 'config');
        }
    }
}
