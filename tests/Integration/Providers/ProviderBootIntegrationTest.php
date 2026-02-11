<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Console\TestConsoleKernel;
use RakkoInc\LaravelGracefulScheduleWorker\Console\TestExceptionHandler;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;

/**
 * ServiceProvider boot() test using Foundation Application
 */
class ProviderBootIntegrationTest extends TestCase
{
    /** @var \Illuminate\Foundation\Application */
    private $app;

    /** @var Container|null */
    private $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        // Save the existing Container instance
        $this->previousContainer = Container::getInstance();

        // Create Application with test fixture as basePath
        $this->app = new \Illuminate\Foundation\Application(
            __DIR__ . '/../../fixture'
        );
        $this->app->singleton(
            \Illuminate\Contracts\Console\Kernel::class,
            TestConsoleKernel::class
        );
        $this->app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            TestExceptionHandler::class
        );
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->flush();
        }

        // Restore the Container instance
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * @testdox GPI.1 boot() registers the schedule:graceful-work command
     */
    public function testBootRegistersGracefulScheduleWorkCommand(): void
    {
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);

        // Get the command list and verify schedule:graceful-work is included
        $allCommands = $kernel->all();
        $this->assertArrayHasKey('schedule:graceful-work', $allCommands);
    }

    /**
     * @testdox GPI.2 boot() registers config file publishing
     */
    public function testBootRegistersConfigPublishing(): void
    {
        $provider = $this->app->getProvider(GracefulScheduleWorkerProvider::class);
        $this->assertNotNull($provider);

        // Get the ServiceProvider's publishes static property via reflection
        $ref = new \ReflectionClass(\Illuminate\Support\ServiceProvider::class);
        $prop = $ref->getProperty('publishes');
        $prop->setAccessible(true);
        $publishes = $prop->getValue();

        $this->assertArrayHasKey(GracefulScheduleWorkerProvider::class, $publishes);

        $paths = $publishes[GracefulScheduleWorkerProvider::class];
        // config/graceful-scheduler.php publish path is registered
        $publishedFiles = array_values($paths);
        $this->assertCount(1, $publishedFiles);
        $this->assertStringContainsString('graceful-scheduler.php', $publishedFiles[0]);
    }

    /**
     * @testdox GPI.3 register() merges graceful-scheduler config
     */
    public function testRegisterMergesConfig(): void
    {
        $this->assertSame('local', config('graceful-scheduler.dispatch'));
        $this->assertIsArray(config('graceful-scheduler.stepfunctions'));
        $this->assertIsArray(config('graceful-scheduler.tracker'));
    }

    /**
     * @testdox GPI.4 StepFunctions binding configures credentials
     */
    public function testStepFunctionsBindingsWithCredentials(): void
    {
        // Set credentials
        config([
            'graceful-scheduler.stepfunctions.credentials.key' => 'test-key',
            'graceful-scheduler.stepfunctions.credentials.secret' => 'test-secret',
            'graceful-scheduler.stepfunctions.state_machine_arn' =>
                'arn:aws:states:ap-northeast-1:123:stateMachine:Test',
        ]);

        // The credentials path is executed on resolution
        $client = $this->app->make(StepFunctionsClientInterface::class);
        $this->assertInstanceOf(AwsSfnClientAdapter::class, $client);

        // Verify the internal SfnClient credentials via reflection
        $adapterRef = new \ReflectionClass($client);
        $clientProp = $adapterRef->getProperty('client');
        $clientProp->setAccessible(true);
        $sfnClient = $clientProp->getValue($client);

        $credentials = $sfnClient->getCredentials()->wait();
        $this->assertSame('test-key', $credentials->getAccessKeyId());
        $this->assertSame('test-secret', $credentials->getSecretKey());
    }

    /**
     * @testdox GPI.5 StepFunctions binding configures endpoint
     */
    public function testStepFunctionsBindingsWithEndpoint(): void
    {
        config([
            'graceful-scheduler.stepfunctions.endpoint' => 'http://localhost:5001',
            'graceful-scheduler.stepfunctions.state_machine_arn' =>
                'arn:aws:states:ap-northeast-1:123:stateMachine:Test',
        ]);

        $client = $this->app->make(StepFunctionsClientInterface::class);
        $this->assertInstanceOf(AwsSfnClientAdapter::class, $client);

        // Verify the internal SfnClient endpoint via reflection
        $adapterRef = new \ReflectionClass($client);
        $clientProp = $adapterRef->getProperty('client');
        $clientProp->setAccessible(true);
        $sfnClient = $clientProp->getValue($client);

        $endpoint = (string) $sfnClient->getEndpoint();
        $this->assertSame('http://localhost:5001', $endpoint);
    }

    /**
     * @testdox GPI.6 StepFunctions binds ExecutionNameGeneratorInterface
     */
    public function testStepFunctionsBindsExecutionNameGenerator(): void
    {
        $generator = $this->app->make(ExecutionNameGeneratorInterface::class);
        $this->assertInstanceOf(ExecutionNameGenerator::class, $generator);
    }

    /**
     * @testdox GPI.7 StepFunctions binds StepFunctionsDispatcher
     */
    public function testStepFunctionsBindsDispatcher(): void
    {
        config([
            'graceful-scheduler.stepfunctions.state_machine_arn' =>
                'arn:aws:states:ap-northeast-1:123:stateMachine:Test',
        ]);

        $dispatcher = $this->app->make(StepFunctionsDispatcher::class);
        $this->assertInstanceOf(StepFunctionsDispatcher::class, $dispatcher);
    }
}
