<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Aws\Sfn\SfnClient;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\LocalStackSfnClientAdapter;

/**
 * StepFunctionsDispatcher の LocalStack/moto を使った Integration テスト
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

    /** @var string */
    private $endpoint;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // State Machine ARN
        self::$stateMachineArn =
            'arn:aws:states:ap-northeast-1:000000000000:stateMachine:GracefulSchedulerStateMachine';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // SFN_ENDPOINT を優先、フォールバックとして LOCALSTACK_ENDPOINT、デフォルトは LocalStack
        $this->endpoint = getenv('SFN_ENDPOINT') ?: (getenv('LOCALSTACK_ENDPOINT') ?: 'http://localhost:4566');

        if (!$this->isSfnEndpointAvailable()) {
            $this->markTestSkipped('Step Functions endpoint is not available: ' . $this->endpoint);
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

        $this->sfnClient = new SfnClient([
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'endpoint' => $this->endpoint,
            'credentials' => [
                'key' => 'test',
                'secret' => 'test',
            ],
            'suppress_php_deprecation_warning' => true,
        ]);

        $this->ensureStateMachineExists();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function ensureStateMachineExists(): void
    {
        $definition = file_get_contents(__DIR__ . '/../../LocalStack/state-machine.json');

        try {
            $this->sfnClient->createStateMachine([
                'name' => 'GracefulSchedulerStateMachine',
                'definition' => $definition,
                'roleArn' => 'arn:aws:iam::000000000000:role/stepfunctions-role',
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            // State machine already exists, ignore
            if ($e->getAwsErrorCode() !== 'StateMachineAlreadyExists') {
                throw $e;
            }
        }
    }

    private function isSfnEndpointAvailable(): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
            ],
        ]);

        // moto の場合
        if ($this->isMotoEndpoint()) {
            $healthUrl = $this->endpoint . '/moto-api/';
            $response = @file_get_contents($healthUrl, false, $context);
            return $response !== false;
        }

        // LocalStack の場合
        $healthUrl = $this->endpoint . '/_localstack/health';
        $response = @file_get_contents($healthUrl, false, $context);
        if ($response === false) {
            return false;
        }

        $health = json_decode($response, true);
        return isset($health['services']['stepfunctions'])
            && in_array($health['services']['stepfunctions'], ['running', 'available'], true);
    }

    private function isMotoEndpoint(): bool
    {
        // ポート 5001 は moto、4566 は LocalStack
        return strpos($this->endpoint, ':5001') !== false;
    }

    private function usesLocalStackAdapter(): bool
    {
        // LocalStack は ExecutionAlreadyExists の代わりに InvalidName を返すため、
        // LocalStackSfnClientAdapter が必要
        return !$this->isMotoEndpoint();
    }

    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    private function createDispatcher(
        bool $useLocalStackAdapter = false,
        ExecutionNameGenerator $nameGenerator = null
    ): StepFunctionsDispatcher {
        $adapter = $useLocalStackAdapter
            ? new LocalStackSfnClientAdapter($this->sfnClient)
            : new AwsSfnClientAdapter($this->sfnClient);
        return new StepFunctionsDispatcher($adapter, self::$stateMachineArn, $this->clock, $nameGenerator);
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
        // テスト実行ごとにユニークな Execution Name を使用
        $uniqueTime = new DateTimeImmutable();
        $fixedName = 'test-duplicate-' . $uniqueTime->format('U-u');
        $nameGenerator = new FixedExecutionNameGenerator($fixedName);

        // 1回目の実行
        $this->clock = new FixedClock($uniqueTime);
        $dispatcher1 = $this->createDispatcher($this->usesLocalStackAdapter(), $nameGenerator);
        $uniqueCommand = 'php artisan test:duplicate-' . $uniqueTime->format('U.u');
        $event = $this->createEvent($uniqueCommand);

        $result1 = $dispatcher1->dispatchEvent($event, $this->app);
        $this->assertTrue($result1->isStarted());
        $this->assertFalse($result1->wasAlreadyRunning());

        // 2回目の実行（同じ Execution Name だが異なる時刻 = 異なる input）
        // AWS/moto の仕様: 同じ name + 同じ input = べき等動作（成功）
        //                  同じ name + 異なる input = ExecutionAlreadyExists
        // LocalStack は ExecutionAlreadyExists の代わりに InvalidName を返す
        $differentTime = $uniqueTime->modify('+1 second');
        $this->clock = new FixedClock($differentTime);
        $dispatcher2 = $this->createDispatcher($this->usesLocalStackAdapter(), $nameGenerator);

        $result2 = $dispatcher2->dispatchEvent($event, $this->app);
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
