<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Aws\Sfn\SfnClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Console\GracefulScheduleWorkCommand;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

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
        $this->app->singleton(LocalDispatcher::class);

        // StepFunctions 関連のバインディング（AWS SDK がインストールされている場合のみ）
        $this->registerStepFunctionsBindings();

        // CompositeDispatcher を ScheduleDispatcherInterface として登録
        $this->app->singleton(ScheduleDispatcherInterface::class, function (Container $app) {
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

            return new CompositeDispatcher($dispatchers, $defaultType);
        });

        // ScheduleOrchestratorInterface を登録
        $this->app->singleton(ScheduleOrchestratorInterface::class, function (Container $app) {
            /** @var ScheduleDispatcherInterface $dispatcher */
            $dispatcher = $app->make(ScheduleDispatcherInterface::class);
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            return new DefaultScheduleOrchestrator($dispatcher, $clock);
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

        // StepFunctionsDispatcher を登録
        $this->app->singleton(StepFunctionsDispatcher::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var string $stateMachineArn */
            $stateMachineArn = $config->get('graceful-scheduler.stepfunctions.state_machine_arn', '');

            /** @var StepFunctionsClientInterface $client */
            $client = $app->make(StepFunctionsClientInterface::class);
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            return new StepFunctionsDispatcher($client, $stateMachineArn, $clock);
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
