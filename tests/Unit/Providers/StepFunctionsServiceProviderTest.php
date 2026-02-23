<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;

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
}
