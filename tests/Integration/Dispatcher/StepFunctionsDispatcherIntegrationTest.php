<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Aws\Sfn\SfnClient;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\FixedExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;

/**
 * StepFunctionsDispatcher の moto を使った Integration テスト
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

    /** @var SfnClient|null */
    private $sfnClient;

    /** @var string */
    private $endpoint;

    /** @var DateTimeImmutable */
    private $dueAt;

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

        // SFN_ENDPOINT を使用（phpunit.xml.dist で設定）
        $this->endpoint = getenv('SFN_ENDPOINT') ?: 'http://localhost:5001';

        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
        $this->dueAt = new DateTimeImmutable();

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
        $definition = file_get_contents(__DIR__ . '/../../StepFunctions/state-machine.json');

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

    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    private function createDispatcher(
        ExecutionNameGeneratorInterface $nameGenerator = null
    ): StepFunctionsDispatcher {
        $adapter = new AwsSfnClientAdapter($this->sfnClient);
        return new StepFunctionsDispatcher(
            $adapter,
            self::$stateMachineArn,
            $nameGenerator ?? new ExecutionNameGenerator()
        );
    }

    /**
     * @testdox T6.1 実際の StartExecution API 呼び出しが成功する
     */
    public function testRealStartExecutionSucceeds(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result);
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
        $dueAt1 = $uniqueTime;
        $dispatcher1 = $this->createDispatcher($nameGenerator);
        $uniqueCommand = 'php artisan test:duplicate-' . $uniqueTime->format('U.u');
        $event = $this->createEvent($uniqueCommand);

        $result1 = $dispatcher1->dispatchEvent($event, $this->app, $dueAt1);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result1);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result1);

        // 2回目の実行（同じ Execution Name だが異なる時刻 = 異なる input）
        // AWS/moto の仕様: 同じ name + 同じ input = べき等動作（成功）
        //                  同じ name + 異なる input = ExecutionAlreadyExists
        $dueAt2 = $uniqueTime->modify('+1 second');
        $dispatcher2 = $this->createDispatcher($nameGenerator);

        $result2 = $dispatcher2->dispatchEvent($event, $this->app, $dueAt2);
        $this->assertInstanceOf(AlreadyRunningDispatchResultInterface::class, $result2);
        $this->assertInstanceOf(AlreadyRunningStepFunctionsDispatchResult::class, $result2);
    }

    /**
     * @testdox T6.3 State Machine が実際に実行される
     */
    public function testStateMachineActuallyExecutes(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan test:execution');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
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
