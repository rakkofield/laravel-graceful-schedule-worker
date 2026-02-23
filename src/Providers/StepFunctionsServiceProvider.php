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
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\LockKeyGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\MutexNameSanitizer;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilder;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilderInterface;
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
        $this->registerSanitizer();
        $this->registerNameGenerator();
        $this->registerLockKeyGenerator();
        $this->registerPayloadBuilder();
        $this->registerDispatcher();
    }

    protected function registerClient(): void
    {
        $this->app->singleton(StepFunctionsClientInterface::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var array{region?: string, version?: string, credentials?: array{key?: string, secret?: string}, endpoint?: string, state_machine_arn?: string} $sfConfig */
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

            /** @var string $stateMachineArn */
            $stateMachineArn = $config->get('graceful-scheduler.stepfunctions.state_machine_arn', '');

            return new AwsSfnClientAdapter($client, $stateMachineArn);
        });
    }

    protected function registerSanitizer(): void
    {
        $this->app->singleton(MutexNameSanitizer::class, function () {
            return new MutexNameSanitizer();
        });
    }

    protected function registerNameGenerator(): void
    {
        $this->app->singleton(ExecutionNameGeneratorInterface::class, function (Container $app) {
            /** @var MutexNameSanitizer $sanitizer */
            $sanitizer = $app->make(MutexNameSanitizer::class);
            return new ExecutionNameGenerator($sanitizer);
        });
    }

    protected function registerLockKeyGenerator(): void
    {
        $this->app->singleton(LockKeyGenerator::class, function (Container $app) {
            /** @var MutexNameSanitizer $sanitizer */
            $sanitizer = $app->make(MutexNameSanitizer::class);
            return new LockKeyGenerator($sanitizer);
        });
    }

    protected function registerPayloadBuilder(): void
    {
        $this->app->singleton(PayloadBuilderInterface::class, function (Container $app) {
            /** @var LockKeyGenerator $lockKeyGenerator */
            $lockKeyGenerator = $app->make(LockKeyGenerator::class);
            return new PayloadBuilder($lockKeyGenerator);
        });
    }

    protected function registerDispatcher(): void
    {
        $this->app->singleton(StepFunctionsDispatcher::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var StepFunctionsClientInterface $client */
            $client = $app->make(StepFunctionsClientInterface::class);

            /** @var ExecutionNameGeneratorInterface $nameGenerator */
            $nameGenerator = $app->make(ExecutionNameGeneratorInterface::class);

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            /** @var int|string $lockTtl */
            $lockTtl = $config->get('graceful-scheduler.stepfunctions.lock_ttl', 3600);
            $lockTtl = (int) $lockTtl;

            /** @var PayloadBuilderInterface $payloadBuilder */
            $payloadBuilder = $app->make(PayloadBuilderInterface::class);

            return new StepFunctionsDispatcher(
                $client,
                $nameGenerator,
                $clock,
                $lockTtl,
                $payloadBuilder
            );
        });
    }
}
