<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;

/**
 * skeleton の Application を使った ServiceProvider の boot() テスト
 *
 * @group skeleton
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

        // 既存の Container インスタンスを退避
        $this->previousContainer = Container::getInstance();

        // skeleton の autoloader を追加で読み込み（Application クラス等を利用可能にする）
        require_once __DIR__ . '/../../../skeleton/vendor/autoload.php';

        // skeleton の Application を生成・ブートストラップ
        $this->app = require __DIR__ . '/../../../skeleton/bootstrap/app.php';
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->flush();
        }

        // Container インスタンスを復元
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * @testdox T3.18 boot() で schedule:graceful-work コマンドが登録される
     */
    public function testBootRegistersGracefulScheduleWorkCommand(): void
    {
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);

        // コマンド一覧を取得して schedule:graceful-work が含まれるか検証
        $allCommands = $kernel->all();
        $this->assertArrayHasKey('schedule:graceful-work', $allCommands);
    }

    /**
     * @testdox T3.19 boot() で config ファイルの publish が登録される
     */
    public function testBootRegistersConfigPublishing(): void
    {
        $provider = $this->app->getProvider(GracefulScheduleWorkerProvider::class);
        $this->assertNotNull($provider);

        // ServiceProvider の publishes 静的プロパティをリフレクションで取得
        $ref = new \ReflectionClass(\Illuminate\Support\ServiceProvider::class);
        $prop = $ref->getProperty('publishes');
        $prop->setAccessible(true);
        $publishes = $prop->getValue();

        $this->assertArrayHasKey(GracefulScheduleWorkerProvider::class, $publishes);

        $paths = $publishes[GracefulScheduleWorkerProvider::class];
        // config/graceful-scheduler.php のパブリッシュパスが登録されている
        $publishedFiles = array_values($paths);
        $this->assertCount(1, $publishedFiles);
        $this->assertStringContainsString('graceful-scheduler.php', $publishedFiles[0]);
    }

    /**
     * @testdox T3.20 register() で graceful-scheduler 設定がマージされる
     */
    public function testRegisterMergesConfig(): void
    {
        $this->assertSame('local', config('graceful-scheduler.dispatch'));
        $this->assertIsArray(config('graceful-scheduler.stepfunctions'));
        $this->assertIsArray(config('graceful-scheduler.tracker'));
    }

    /**
     * @testdox T3.21 StepFunctions バインディングで credentials が設定される
     */
    public function testStepFunctionsBindingsWithCredentials(): void
    {
        // credentials を設定
        config([
            'graceful-scheduler.stepfunctions.credentials.key' => 'test-key',
            'graceful-scheduler.stepfunctions.credentials.secret' => 'test-secret',
            'graceful-scheduler.stepfunctions.state_machine_arn' =>
                'arn:aws:states:ap-northeast-1:123:stateMachine:Test',
        ]);

        // 解決時に credentials パスが実行される
        $client = $this->app->make(StepFunctionsClientInterface::class);
        $this->assertInstanceOf(AwsSfnClientAdapter::class, $client);

        // リフレクションで内部 SfnClient の credentials 設定を検証
        $adapterRef = new \ReflectionClass($client);
        $clientProp = $adapterRef->getProperty('client');
        $clientProp->setAccessible(true);
        $sfnClient = $clientProp->getValue($client);

        $credentials = $sfnClient->getCredentials()->wait();
        $this->assertSame('test-key', $credentials->getAccessKeyId());
        $this->assertSame('test-secret', $credentials->getSecretKey());
    }

    /**
     * @testdox T3.22 StepFunctions バインディングで endpoint が設定される
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

        // リフレクションで内部 SfnClient の endpoint 設定を検証
        $adapterRef = new \ReflectionClass($client);
        $clientProp = $adapterRef->getProperty('client');
        $clientProp->setAccessible(true);
        $sfnClient = $clientProp->getValue($client);

        $endpoint = (string) $sfnClient->getEndpoint();
        $this->assertSame('http://localhost:5001', $endpoint);
    }

    /**
     * @testdox T3.23 StepFunctions の ExecutionNameGeneratorInterface がバインドされる
     */
    public function testStepFunctionsBindsExecutionNameGenerator(): void
    {
        $generator = $this->app->make(ExecutionNameGeneratorInterface::class);
        $this->assertInstanceOf(ExecutionNameGenerator::class, $generator);
    }

    /**
     * @testdox T3.24 StepFunctions の StepFunctionsDispatcher がバインドされる
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
