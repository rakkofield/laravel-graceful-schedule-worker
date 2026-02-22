<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Aws\Sfn\SfnClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;

/**
 * Registers AWS Step Functions related bindings.
 *
 * Only active when the AWS SDK is installed.
 */
class StepFunctionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!class_exists(SfnClient::class)) {
            return;
        }

        $this->registerClient();
        $this->registerNameGenerator();
        $this->registerDispatcher();
    }

    protected function registerClient(): void
    {
        $this->app->singleton(StepFunctionsClientInterface::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var array{region?: string, version?: string, credentials?: array{key?: string, secret?: string}, endpoint?: string} $sfConfig */
            $sfConfig = $config->get('graceful-scheduler.stepfunctions', []);

            $clientConfig = [
                'region' => $sfConfig['region'] ?? 'ap-northeast-1',
                'version' => $sfConfig['version'] ?? 'latest',
            ];

            if (!empty($sfConfig['credentials']['key']) && !empty($sfConfig['credentials']['secret'])) {
                $clientConfig['credentials'] = [
                    'key' => $sfConfig['credentials']['key'],
                    'secret' => $sfConfig['credentials']['secret'],
                ];
            }

            if (isset($sfConfig['endpoint'])) {
                $clientConfig['endpoint'] = $sfConfig['endpoint'];
            }

            $client = new SfnClient($clientConfig);

            return new AwsSfnClientAdapter($client);
        });
    }

    protected function registerNameGenerator(): void
    {
        $this->app->singleton(ExecutionNameGeneratorInterface::class, ExecutionNameGenerator::class);
    }

    protected function registerDispatcher(): void
    {
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

            /** @var int|string $lockTtl */
            $lockTtl = $config->get('graceful-scheduler.stepfunctions.lock_ttl', 3600);
            $lockTtl = (int) $lockTtl;

            return new StepFunctionsDispatcher(
                $client,
                $stateMachineArn,
                $nameGenerator,
                $clock,
                $lockTtl
            );
        });
    }
}
