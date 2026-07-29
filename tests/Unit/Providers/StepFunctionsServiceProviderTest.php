<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\LockKeyGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilder;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilderInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionInputFactoryInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsTimeoutSettings;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

class StepFunctionsServiceProviderTest extends TestCase
{
    /** @var FakeApplication */
    private $app;

    /** @var StepFunctionsServiceProvider */
    private $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new FakeApplication();
        Container::setInstance($this->app);

        $this->app->singleton('config', function () {
            return new class {
                /** @var array<string, mixed> */
                private $config = [
                    'graceful-scheduler' => [
                        'stepfunctions' => [
                            'region' => 'ap-northeast-1',
                            'version' => 'latest',
                            'state_machine_arn' => 'arn:aws:states:ap-northeast-1:123:stateMachine:Test',
                            'endpoint' => 'http://localhost:5001',
                            'lock_ttl' => 3600,
                        ],
                    ],
                ];

                /**
                 * @param string $key
                 * @param mixed $default
                 * @return mixed
                 */
                public function get(string $key, $default = null)
                {
                    $keys = explode('.', $key);
                    $value = $this->config;

                    foreach ($keys as $k) {
                        if (!isset($value[$k])) {
                            return $default;
                        }
                        $value = $value[$k];
                    }

                    return $value;
                }

                /**
                 * @param string|array<string, mixed> $key
                 * @param mixed $value
                 * @return void
                 */
                public function set($key, $value = null): void
                {
                    if (is_array($key)) {
                        foreach ($key as $k => $v) {
                            $this->setOne($k, $v);
                        }
                    } else {
                        $this->setOne($key, $value);
                    }
                }

                /**
                 * @param string $key
                 * @param mixed $value
                 * @return void
                 */
                private function setOne(string $key, $value): void
                {
                    $keys = explode('.', $key);
                    $config = &$this->config;

                    foreach ($keys as $k) {
                        if (!isset($config[$k]) || !is_array($config[$k])) {
                            $config[$k] = [];
                        }
                        $config = &$config[$k];
                    }

                    $config = $value;
                }
            };
        });

        $this->app->singleton('log', function () {
            return new NullLogger();
        });

        $this->app->singleton(ClockInterface::class, SystemClock::class);

        $this->provider = new StepFunctionsServiceProvider($this->app);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox SFP.1 Registers StepFunctionsClientInterface as a singleton
     */
    public function testRegistersStepFunctionsClientInterfaceAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(StepFunctionsClientInterface::class));
        $this->assertTrue($this->app->isShared(StepFunctionsClientInterface::class));

        $client = $this->app->make(StepFunctionsClientInterface::class);
        $this->assertInstanceOf(AwsSfnClientAdapter::class, $client);
    }

    /**
     * @testdox SFP.2 StepFunctionsClientInterface returns the same instance
     */
    public function testStepFunctionsClientReturnsSameInstance(): void
    {
        $this->provider->register();

        $client1 = $this->app->make(StepFunctionsClientInterface::class);
        $client2 = $this->app->make(StepFunctionsClientInterface::class);

        $this->assertSame($client1, $client2);
    }

    /**
     * @testdox SFP.3 Registers ExecutionNameGeneratorInterface as a singleton
     */
    public function testRegistersExecutionNameGeneratorInterfaceAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ExecutionNameGeneratorInterface::class));
        $this->assertTrue($this->app->isShared(ExecutionNameGeneratorInterface::class));

        $generator = $this->app->make(ExecutionNameGeneratorInterface::class);
        $this->assertInstanceOf(ExecutionNameGenerator::class, $generator);
    }

    /**
     * @testdox SFP.4 Registers StepFunctionsDispatcher as a singleton
     */
    public function testRegistersStepFunctionsDispatcherAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(StepFunctionsDispatcher::class));
        $this->assertTrue($this->app->isShared(StepFunctionsDispatcher::class));

        $dispatcher = $this->app->make(StepFunctionsDispatcher::class);
        $this->assertInstanceOf(StepFunctionsDispatcher::class, $dispatcher);
    }

    /**
     * @testdox SFP.5 StepFunctionsDispatcher returns the same instance
     */
    public function testStepFunctionsDispatcherReturnsSameInstance(): void
    {
        $this->provider->register();

        $dispatcher1 = $this->app->make(StepFunctionsDispatcher::class);
        $dispatcher2 = $this->app->make(StepFunctionsDispatcher::class);

        $this->assertSame($dispatcher1, $dispatcher2);
    }

    /**
     * @testdox SFP.6 StepFunctions lockTtl handles string value from config
     */
    public function testStepFunctionsLockTtlHandlesStringFromConfig(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_ttl', '7200');

        $this->provider->register();

        $dispatcher = $this->app->make(StepFunctionsDispatcher::class);
        $this->assertInstanceOf(StepFunctionsDispatcher::class, $dispatcher);
    }

    /**
     * @testdox SFP.7 Client configures credentials when provided
     */
    public function testClientConfiguresCredentialsWhenProvided(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.credentials.key', 'test-key');
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.credentials.secret', 'test-secret');

        $this->provider->register();

        $client = $this->app->make(StepFunctionsClientInterface::class);
        $this->assertInstanceOf(AwsSfnClientAdapter::class, $client);

        $adapterRef = new \ReflectionClass($client);
        $clientProp = $adapterRef->getProperty('client');
        $clientProp->setAccessible(true);
        $sfnClient = $clientProp->getValue($client);

        $credentials = $sfnClient->getCredentials()->wait();
        $this->assertSame('test-key', $credentials->getAccessKeyId());
        $this->assertSame('test-secret', $credentials->getSecretKey());
    }

    /**
     * @testdox SFP.8 Client configures endpoint when provided
     */
    public function testClientConfiguresEndpointWhenProvided(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.endpoint', 'http://localhost:9999');

        $this->provider->register();

        $client = $this->app->make(StepFunctionsClientInterface::class);
        $this->assertInstanceOf(AwsSfnClientAdapter::class, $client);

        $adapterRef = new \ReflectionClass($client);
        $clientProp = $adapterRef->getProperty('client');
        $clientProp->setAccessible(true);
        $sfnClient = $clientProp->getValue($client);

        $endpoint = (string) $sfnClient->getEndpoint();
        $this->assertSame('http://localhost:9999', $endpoint);
    }

    /**
     * @testdox SFP.9 Registers LockKeyGenerator as a singleton
     */
    public function testRegistersLockKeyGeneratorAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(LockKeyGenerator::class));
        $this->assertTrue($this->app->isShared(LockKeyGenerator::class));

        $generator = $this->app->make(LockKeyGenerator::class);
        $this->assertInstanceOf(LockKeyGenerator::class, $generator);
    }

    /**
     * @testdox SFP.10 Registers PayloadBuilderInterface as a singleton
     */
    public function testRegistersPayloadBuilderInterfaceAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(PayloadBuilderInterface::class));
        $this->assertTrue($this->app->isShared(PayloadBuilderInterface::class));

        $builder = $this->app->make(PayloadBuilderInterface::class);
        $this->assertInstanceOf(PayloadBuilder::class, $builder);
    }

    /**
     * @testdox SFP.11 PayloadBuilderInterface returns the same instance
     */
    public function testPayloadBuilderReturnsSameInstance(): void
    {
        $this->provider->register();

        $builder1 = $this->app->make(PayloadBuilderInterface::class);
        $builder2 = $this->app->make(PayloadBuilderInterface::class);

        $this->assertSame($builder1, $builder2);
    }

    /**
     * @testdox SFP.12 Client configures callable credentials when provided
     */
    public function testClientConfiguresCallableCredentialsWhenProvided(): void
    {
        $credentials = new \Aws\Credentials\Credentials('callable-key', 'callable-secret');
        $provider = \Aws\Credentials\CredentialProvider::fromCredentials($credentials);
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.credentials', $provider);

        $this->provider->register();

        $client = $this->app->make(StepFunctionsClientInterface::class);
        $this->assertInstanceOf(AwsSfnClientAdapter::class, $client);

        $adapterRef = new \ReflectionClass($client);
        $clientProp = $adapterRef->getProperty('client');
        $clientProp->setAccessible(true);
        $sfnClient = $clientProp->getValue($client);

        $resolved = $sfnClient->getCredentials()->wait();
        $this->assertSame('callable-key', $resolved->getAccessKeyId());
        $this->assertSame('callable-secret', $resolved->getSecretKey());
    }

    /**
     * @testdox SFP.13 Timeout settings are read from config as a validated set
     */
    public function testTimeoutSettingsAreReadFromConfig(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_ttl', '7200');
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_release_buffer', '300');
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.min_task_timeout', '120');

        $this->provider->register();

        /** @var StepFunctionsTimeoutSettings $settings */
        $settings = $this->app->make(StepFunctionsTimeoutSettings::class);
        $this->assertSame(7200, $settings->getLockTtlSeconds());
        $this->assertSame(300, $settings->getLockReleaseBufferSeconds());
        $this->assertSame(120, $settings->getMinTaskTimeoutSeconds());
    }

    /**
     * @testdox SFP.14 Timeout settings fall back to the documented defaults
     */
    public function testTimeoutSettingsFallBackToDefaults(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions', [
            'state_machine_arn' => 'arn:aws:states:ap-northeast-1:123:stateMachine:Test',
        ]);

        $this->provider->register();

        /** @var StepFunctionsTimeoutSettings $settings */
        $settings = $this->app->make(StepFunctionsTimeoutSettings::class);
        $this->assertSame(3600, $settings->getLockTtlSeconds());
        $this->assertSame(60, $settings->getLockReleaseBufferSeconds());
        $this->assertSame(60, $settings->getMinTaskTimeoutSeconds());
    }

    /**
     * @testdox SFP.15 Timeout settings are a singleton shared by PayloadBuilder and input factory
     */
    public function testTimeoutSettingsAreSharedSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->isShared(StepFunctionsTimeoutSettings::class));
        $this->assertSame(
            $this->app->make(StepFunctionsTimeoutSettings::class),
            $this->app->make(StepFunctionsTimeoutSettings::class)
        );
    }

    /**
     * @testdox SFP.16 The configured lock_release_buffer reaches the dispatched input
     */
    public function testConfiguredLockReleaseBufferReachesDispatchedInput(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_release_buffer', 300);

        $this->provider->register();

        $input = $this->createInputThroughContainer();

        $this->assertSame(3600 - 300, $input['timeoutSeconds']);
    }

    /**
     * @testdox SFP.17 The configured lock_ttl reaches expiresAt and the derived timeout
     */
    public function testConfiguredLockTtlReachesExpiresAtAndTimeout(): void
    {
        // Guards the wiring itself, through the input factory that receives lock_ttl:
        // lock_ttl and lock_release_buffer are two same-typed getters on one settings
        // object, so swapping them at either call site has to fail here.
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_ttl', 7200);

        $this->provider->register();

        $dueAt = new \DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $input = $this->createInputThroughContainer($dueAt);

        $this->assertSame($dueAt->getTimestamp() + 7200, $input['expiresAt']);
        $this->assertSame(7200 - 60, $input['timeoutSeconds']);
    }

    /**
     * @testdox SFP.18 Resolving the PayloadBuilder rejects an incoherent lock_ttl / buffer pair
     */
    public function testPayloadBuilderRejectsIncoherentTimeoutSettings(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_ttl', 30);
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_release_buffer', 600);

        $this->provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_ttl (30) must be greater than lock_release_buffer (600)');

        $this->app->make(PayloadBuilderInterface::class);
    }

    /**
     * @testdox SFP.19 Resolving the input factory rejects an incoherent lock_ttl / buffer pair
     */
    public function testInputFactoryRejectsIncoherentTimeoutSettings(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_ttl', 60);

        $this->provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_ttl (60) must be greater than lock_release_buffer (60)');

        $this->app->make(StartExecutionInputFactoryInterface::class);
    }

    /**
     * @testdox SFP.20 Resolving the dispatcher rejects a non-positive lock_ttl
     */
    public function testDispatcherRejectsNonPositiveLockTtl(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_ttl', 0);

        $this->provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_ttl must be at least 1 second, got 0');

        $this->app->make(StepFunctionsDispatcher::class);
    }

    /**
     * @testdox SFP.21 Resolving rejects a min_task_timeout larger than the configured budget
     */
    public function testRejectsMinTaskTimeoutLargerThanBudget(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.min_task_timeout', 3600);

        $this->provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('min_task_timeout (3600) must not exceed');

        $this->app->make(StepFunctionsTimeoutSettings::class);
    }

    /**
     * @testdox SFP.22 A non-numeric seconds setting is rejected with the raw value quoted
     */
    public function testNonNumericSecondsSettingIsRejectedWithRawValue(): void
    {
        $this->app->make('config')->set('graceful-scheduler.stepfunctions.lock_release_buffer', '30m');

        $this->provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("lock_release_buffer must be an integer number of seconds, got '30m'");

        $this->app->make(StepFunctionsTimeoutSettings::class);
    }

    /**
     * Produce a StartExecution input through the whole container-resolved graph and
     * return its decoded payload, so the assertions cover the provider's wiring - which
     * setting reaches which collaborator - rather than a hand-assembled object set.
     *
     * @param \DateTimeImmutable|null $dueAt
     * @return array<string, mixed>
     */
    private function createInputThroughContainer(\DateTimeImmutable $dueAt = null): array
    {
        $dueAt = $dueAt ?? new \DateTimeImmutable('2024-01-15T10:30:00+09:00');

        /** @var StartExecutionInputFactoryInterface $factory */
        $factory = $this->app->make(StartExecutionInputFactoryInterface::class);

        $mutex = new FakeEventMutex();
        $clock = new FixedClock($dueAt);
        $event = new ClockAwareEvent($mutex, 'php artisan report:daily', $clock, 'local', null, new TimezoneResolver());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($factory->create($event, $dueAt, $dueAt)->getInput(), true);
        return $decoded;
    }
}
