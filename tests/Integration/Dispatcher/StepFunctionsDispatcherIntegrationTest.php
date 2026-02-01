<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Integration\Dispatcher;

use Aws\Sfn\SfnClient;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;

/**
 * StepFunctionsDispatcher の LocalStack を使った Integration テスト
 *
 * @group integration
 * @group stepfunctions
 */
class StepFunctionsDispatcherIntegrationTest extends TestCase
{
    /** @var string */
    private static $stateMachineArn;

    /** @var Container */
    private $app;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var FixedClock */
    private $clock;

    /** @var SfnClient|null */
    private $sfnClient;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // LocalStack の State Machine ARN
        self::$stateMachineArn =
            'arn:aws:states:ap-northeast-1:000000000000:stateMachine:GracefulSchedulerStateMachine';
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isLocalStackAvailable()) {
            $this->markTestSkipped('LocalStack is not available');
        }

        if (!class_exists(SfnClient::class)) {
            $this->markTestSkipped('aws/aws-sdk-php is not installed');
        }

        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
        $this->clock = new FixedClock(new DateTimeImmutable());

        $endpoint = getenv('LOCALSTACK_ENDPOINT') ?: 'http://localhost:4566';
        $this->sfnClient = new SfnClient([
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => [
                'key' => 'test',
                'secret' => 'test',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function isLocalStackAvailable(): bool
    {
        $endpoint = getenv('LOCALSTACK_ENDPOINT') ?: 'http://localhost:4566';
        $healthUrl = $endpoint . '/_localstack/health';

        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
            ],
        ]);

        $response = @file_get_contents($healthUrl, false, $context);
        if ($response === false) {
            return false;
        }

        $health = json_decode($response, true);
        return isset($health['services']['stepfunctions']) && $health['services']['stepfunctions'] === 'running';
    }

    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    private function createDispatcher(): StepFunctionsDispatcher
    {
        $adapter = new AwsSfnClientAdapter($this->sfnClient);
        return new StepFunctionsDispatcher($adapter, self::$stateMachineArn, $this->clock);
    }

    /**
     * @testdox T6.1 実際の StartExecution API 呼び出しが成功する
     */
    public function testRealStartExecutionSucceeds(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($result->isStarted());
        $this->assertNull($result->getError());
        $this->assertNotNull($result->getExecutionArn());
        $this->assertStringContainsString('arn:aws:states:', $result->getExecutionArn());
    }

    /**
     * @testdox T6.2 同一 Execution Name での2回目呼び出しで ExecutionAlreadyExists
     */
    public function testDuplicateExecutionReturnsAlreadyRunning(): void
    {
        // 固定時刻を使用して同じ Execution Name になるようにする
        $fixedTime = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $this->clock = new FixedClock($fixedTime);

        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan test:duplicate');

        // 1回目の実行
        $result1 = $dispatcher->dispatchEvent($event, $this->app);
        $this->assertTrue($result1->isStarted());
        $this->assertFalse($result1->wasAlreadyRunning());

        // 2回目の実行（同じ Execution Name）
        $result2 = $dispatcher->dispatchEvent($event, $this->app);
        $this->assertTrue($result2->isStarted());
        $this->assertTrue($result2->wasAlreadyRunning());
    }

    /**
     * @testdox T6.3 State Machine が実際に実行される
     */
    public function testStateMachineActuallyExecutes(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan test:execution');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($result->isStarted());
        $executionArn = $result->getExecutionArn();
        $this->assertNotNull($executionArn);

        // 実行状態を確認
        $description = $this->sfnClient->describeExecution([
            'executionArn' => $executionArn,
        ]);

        // Pass State なのでほぼ即座に完了する
        $this->assertContains($description['status'], ['RUNNING', 'SUCCEEDED']);
    }
}
