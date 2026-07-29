<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Aws\Sfn\SfnClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StepFunctionsDispatchResultFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\LockKeyGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\MutexNameSanitizer;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilder;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilderInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionInputFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionInputFactoryInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsTimeoutSettings;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;

/**
 * Registers AWS Step Functions related bindings.
 *
 * Only active when the AWS SDK is installed.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Wires all StepFunctions components into the container
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
        $this->registerTimeoutSettings();
        $this->registerPayloadBuilder();
        $this->registerInputFactory();
        $this->registerDispatcher();
    }

    /**
     * Single validated source for the timeout-related settings. Both the PayloadBuilder
     * and the input factory read them from here, so the values can never be validated in
     * one place and used from another.
     */
    protected function registerTimeoutSettings(): void
    {
        $this->app->singleton(StepFunctionsTimeoutSettings::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new StepFunctionsTimeoutSettings(
                self::toSeconds('lock_ttl', $config->get(
                    'graceful-scheduler.stepfunctions.lock_ttl',
                    StepFunctionsTimeoutSettings::DEFAULT_LOCK_TTL
                )),
                self::toSeconds('lock_release_buffer', $config->get(
                    'graceful-scheduler.stepfunctions.lock_release_buffer',
                    StepFunctionsTimeoutSettings::DEFAULT_LOCK_RELEASE_BUFFER
                )),
                self::toSeconds('min_task_timeout', $config->get(
                    'graceful-scheduler.stepfunctions.min_task_timeout',
                    StepFunctionsTimeoutSettings::DEFAULT_MIN_TASK_TIMEOUT
                ))
            );
        });
    }

    /**
     * Coerce a second-valued stepfunctions setting to int.
     *
     * Config arrives via env(), so the value is a string. Casting straight to int would
     * turn '30m' into 30 and 'abc' into 0, reporting a value the operator never wrote,
     * so anything non-numeric is rejected with the raw value quoted.
     *
     * @param string $key
     * @param mixed $value
     * @return int
     */
    private static function toSeconds(string $key, $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || !preg_match('/\A-?\d+\z/', $value)) {
            throw new InvalidArgumentException(sprintf(
                'graceful-scheduler.stepfunctions.%s must be an integer number of seconds, got %s.',
                $key,
                is_scalar($value) ? var_export($value, true) : gettype($value)
            ));
        }

        return (int) $value;
    }

    protected function registerClient(): void
    {
        $this->app->singleton(StepFunctionsClientInterface::class, function (Container $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var array{region?: string, version?: string, credentials?: array{key?: string, secret?: string}|callable, endpoint?: string, state_machine_arn?: string} $sfConfig */
            $sfConfig = $config->get('graceful-scheduler.stepfunctions', []);

            $clientConfig = [
                'region' => $sfConfig['region'] ?? 'ap-northeast-1',
                'version' => $sfConfig['version'] ?? 'latest',
            ];

            if (isset($sfConfig['credentials']) && is_callable($sfConfig['credentials'])) {
                $clientConfig['credentials'] = $sfConfig['credentials'];
            } elseif (
                isset($sfConfig['credentials'])
                && is_array($sfConfig['credentials'])
                && !empty($sfConfig['credentials']['key'])
                && !empty($sfConfig['credentials']['secret'])
            ) {
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

            /** @var StepFunctionsTimeoutSettings $settings */
            $settings = $app->make(StepFunctionsTimeoutSettings::class);

            return new PayloadBuilder($lockKeyGenerator, $settings);
        });
    }

    protected function registerInputFactory(): void
    {
        $this->app->singleton(StartExecutionInputFactoryInterface::class, function (Container $app) {
            /** @var ExecutionNameGeneratorInterface $nameGenerator */
            $nameGenerator = $app->make(ExecutionNameGeneratorInterface::class);

            /** @var PayloadBuilderInterface $payloadBuilder */
            $payloadBuilder = $app->make(PayloadBuilderInterface::class);

            /** @var StepFunctionsTimeoutSettings $settings */
            $settings = $app->make(StepFunctionsTimeoutSettings::class);

            return new StartExecutionInputFactory(
                $nameGenerator,
                $payloadBuilder,
                $settings->getLockTtlSeconds()
            );
        });
    }

    protected function registerDispatcher(): void
    {
        $this->app->singleton(StepFunctionsDispatcher::class, function (Container $app) {
            /** @var StepFunctionsClientInterface $client */
            $client = $app->make(StepFunctionsClientInterface::class);

            /** @var StartExecutionInputFactoryInterface $inputFactory */
            $inputFactory = $app->make(StartExecutionInputFactoryInterface::class);

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $resultFactory = new StepFunctionsDispatchResultFactory($clock);

            return new StepFunctionsDispatcher($client, $inputFactory, $resultFactory);
        });
    }
}
