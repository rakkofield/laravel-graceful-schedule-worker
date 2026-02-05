<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStepFunctionsClient;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;

/**
 * @testdox StepFunctionsDispatcher
 */
class StepFunctionsDispatcherTest extends TestCase
{
    /** @var Container */
    private $app;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var FakeStepFunctionsClient */
    private $client;

    /** @var FixedClock */
    private $clock;

    /** @var string */
    private $stateMachineArn = 'arn:aws:states:ap-northeast-1:123456789012:stateMachine:TestStateMachine';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
        $this->client = new FakeStepFunctionsClient();
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15T10:30:00+09:00'));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    private function createDispatcher(): StepFunctionsDispatcher
    {
        return new StepFunctionsDispatcher(
            $this->client,
            $this->stateMachineArn,
            $this->clock,
            new ExecutionNameGenerator()
        );
    }

    /**
     * @testdox T5.1 startExecution 成功時に StepFunctionsDispatchResult を返す
     */
    public function testReturnsStepFunctionsDispatchResultOnSuccess(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox T5.2 ExecutionAlreadyExists で StartedDispatchResultInterface と wasAlreadyRunning=true
     */
    public function testReturnsAlreadyRunningOnExecutionAlreadyExists(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $this->client->willThrowExecutionAlreadyExists('test-execution');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertTrue($result->wasAlreadyRunning());
    }

    /**
     * @testdox T5.3 Execution Name が mutexName + timestamp から生成される
     */
    public function testExecutionNameIsGeneratedFromMutexAndTimestamp(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->app);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);
        // mutexName に不正な文字が含まれていても sanitize される
        $this->assertStringContainsString('2024-01-15T10-30-00', $execution['name']);
    }

    /**
     * @testdox T5.4 入力 JSON に command, mutexName, dueAt が含まれる
     */
    public function testInputContainsRequiredFields(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->app);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);

        $input = json_decode($execution['input'], true);
        $this->assertArrayHasKey('command', $input);
        $this->assertArrayHasKey('mutexName', $input);
        $this->assertArrayHasKey('dueAt', $input);
        $this->assertSame('php artisan report:daily', $input['command']);
        $this->assertSame($event->mutexName(), $input['mutexName']);
        $this->assertSame('2024-01-15T10:30:00+09:00', $input['dueAt']);
    }

    /**
     * @testdox T5.5 getDispatcherType() が 'stepfunctions' を返す
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox T5.6 一般エラー時に FailedDispatchResultInterface と getError() でメッセージ
     */
    public function testReturnsFailedOnGeneralError(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $this->client->willThrowError('Connection refused');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(FailedStepFunctionsDispatchResult::class, $result);
        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertFalse($result->wasAlreadyRunning());
        $this->assertStringContainsString('Connection refused', $result->getError());
    }

    /**
     * @testdox T5.7 正しい stateMachineArn が使用される
     */
    public function testUsesCorrectStateMachineArn(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->app);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);
        $this->assertSame($this->stateMachineArn, $execution['stateMachineArn']);
    }

    /**
     * @testdox T5.8 成功時に executionArn が取得できる
     */
    public function testReturnsExecutionArnOnSuccess(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertNotNull($result->getExecutionArn());
        $this->assertStringContainsString('arn:aws:states:', $result->getExecutionArn());
    }

    /**
     * @testdox T5.9 イベント識別子が正しく返される
     */
    public function testReturnsCorrectEventIdentifier(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
    }

    /**
     * @testdox T5.10 コマンドが正しく返される
     */
    public function testReturnsCorrectEventCommand(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame('php artisan report:daily', $result->getEventCommand());
    }

    /**
     * @testdox T5.11 一般エラー時に getException() で例外を取得できる
     */
    public function testReturnsExceptionOnGeneralError(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $this->client->willThrowError('Connection refused');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertNotNull($result->getException());
        $this->assertSame('Connection refused', $result->getException()->getMessage());
    }

    /**
     * @testdox T5.12 cleanup が例外をスローしない（no-op）
     */
    public function testCleanupIsNoOp(): void
    {
        $dispatcher = $this->createDispatcher();

        // 例外がスローされないことを確認
        $dispatcher->cleanup();

        // no-op なので特にアサーションはないが、例外が発生しないことを確認
        $this->assertTrue(true);
    }

    /**
     * @testdox T5.13 stopAll が例外をスローしない（no-op）
     */
    public function testStopAllIsNoOp(): void
    {
        $dispatcher = $this->createDispatcher();

        // 例外がスローされないことを確認
        $dispatcher->stopAll();

        // no-op なので特にアサーションはないが、例外が発生しないことを確認
        $this->assertTrue(true);
    }
}
