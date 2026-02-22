<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\FakeStepFunctionsClient;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;

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

    /** @var DateTimeImmutable */
    private $dueAt;

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
        $this->dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock, 'local');
    }

    private function createDispatcher(): StepFunctionsDispatcher
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        return new StepFunctionsDispatcher(
            $this->client,
            $this->stateMachineArn,
            new ExecutionNameGenerator(),
            $clock,
            3600
        );
    }

    /**
     * @testdox SFD.1 Returns StepFunctionsDispatchResult on successful startExecution
     */
    public function testReturnsStepFunctionsDispatchResultOnSuccess(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SFD.2 Returns AlreadyRunningDispatchResultInterface on ExecutionAlreadyExists
     */
    public function testReturnsAlreadyRunningOnExecutionAlreadyExists(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $this->client->willThrowExecutionAlreadyExists('test-execution');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(AlreadyRunningStepFunctionsDispatchResult::class, $result);
        $this->assertInstanceOf(AlreadyRunningDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SFD.3 Execution Name is generated from mutexName + timestamp
     */
    public function testExecutionNameIsGeneratedFromMutexAndTimestamp(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);
        // Invalid characters in mutexName are sanitized
        $this->assertStringContainsString('1705282200', $execution['name']);
    }

    /**
     * @testdox SFD.4 Input JSON contains command, mutexName, dueAt, lockKey, and ttl
     */
    public function testInputContainsRequiredFields(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);

        $input = json_decode($execution['input'], true);
        $this->assertArrayHasKey('command', $input);
        $this->assertArrayHasKey('mutexName', $input);
        $this->assertArrayHasKey('dueAt', $input);
        $this->assertArrayHasKey('lockKey', $input);
        $this->assertArrayHasKey('ttl', $input);
        $this->assertSame('php artisan report:daily', $input['command']);
        $this->assertSame($event->mutexName(), $input['mutexName']);
        $this->assertSame('2024-01-15T10:30:00+09:00', $input['dueAt']);
        $this->assertIsString($input['lockKey']);
        $this->assertIsInt($input['ttl']);
    }

    /**
     * @testdox SFD.5 getDispatcherType() returns 'stepfunctions'
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox SFD.6 Returns FailedDispatchResultInterface with error message on general error
     */
    public function testReturnsFailedOnGeneralError(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $this->client->willThrowError('Connection refused');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(FailedStepFunctionsDispatchResult::class, $result);
        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertStringContainsString('Connection refused', $result->getError());
    }

    /**
     * @testdox SFD.7 Correct stateMachineArn is used
     */
    public function testUsesCorrectStateMachineArn(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);
        $this->assertSame($this->stateMachineArn, $execution['stateMachineArn']);
    }

    /**
     * @testdox SFD.8 executionArn is available on success
     */
    public function testReturnsExecutionArnOnSuccess(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertNotNull($result->getExecutionArn());
        $this->assertStringContainsString('arn:aws:states:', $result->getExecutionArn());
    }

    /**
     * @testdox SFD.9 Returns correct event identifier
     */
    public function testReturnsCorrectEventIdentifier(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
    }

    /**
     * @testdox SFD.10 Returns correct event command
     */
    public function testReturnsCorrectEventCommand(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertSame('php artisan report:daily', $result->getEventCommand());
    }

    /**
     * @testdox SFD.11 getException() returns the exception on general error
     */
    public function testReturnsExceptionOnGeneralError(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $this->client->willThrowError('Connection refused');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertNotNull($result->getException());
        $this->assertSame('Connection refused', $result->getException()->getMessage());
    }

    /**
     * @testdox SFD.12 cleanup does not throw (no-op)
     */
    public function testCleanupIsNoOp(): void
    {
        $dispatcher = $this->createDispatcher();

        // Verify no exception is thrown
        $dispatcher->cleanup();

        // No assertion needed since it's a no-op; just verify no exception occurs
        $this->assertTrue(true);
    }

    /**
     * @testdox SFD.13 stopAll does not throw (no-op)
     */
    public function testStopAllIsNoOp(): void
    {
        $dispatcher = $this->createDispatcher();

        // Verify no exception is thrown
        $dispatcher->stopAll();

        // No assertion needed since it's a no-op; just verify no exception occurs
        $this->assertTrue(true);
    }

    /**
     * @testdox SFD.14 json_encode failure returns FailedStepFunctionsDispatchResult
     */
    public function testJsonEncodeFailureReturnsFailedResult(): void
    {
        $dispatcher = $this->createDispatcher();
        // Force json_encode failure with invalid UTF-8 string
        $event = $this->createEvent("\xFF\xFE");

        $result = $dispatcher->dispatchEvent($event, $this->dueAt);

        $this->assertInstanceOf(FailedStepFunctionsDispatchResult::class, $result);
        $this->assertStringContainsString('Failed to encode input JSON', $result->getError());
    }

    /**
     * @testdox SFD.15 Constructor throws InvalidArgumentException for empty stateMachineArn
     */
    public function testConstructorThrowsForEmptyStateMachineArn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stateMachineArn cannot be empty');

        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        new StepFunctionsDispatcher(
            $this->client,
            '',
            new ExecutionNameGenerator(),
            $clock,
            3600
        );
    }

    /**
     * @testdox SFD.16 Non-StepFunctionsException propagates instead of being caught
     */
    public function testNonStepFunctionsExceptionPropagates(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        // Throw a plain RuntimeException (not StepFunctionsException)
        $this->client->willThrowCustomException(new \RuntimeException('Database connection lost'));

        // Only StepFunctionsException is caught; other exceptions propagate
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection lost');

        $dispatcher->dispatchEvent($event, $this->dueAt);
    }

    /**
     * @testdox SFD.17 Uses rawCommand when available on event
     */
    public function testUsesRawCommandWhenAvailable(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');
        $event->setRawCommand('report:daily');

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);

        $input = json_decode($execution['input'], true);
        $this->assertSame('report:daily', $input['command']);
    }

    /**
     * @testdox SFD.18 Falls back to event->command when rawCommand is null
     */
    public function testFallsBackToEventCommandWhenRawCommandIsNull(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');
        // rawCommand is null by default

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);

        $input = json_decode($execution['input'], true);
        $this->assertSame('php artisan report:daily', $input['command']);
    }

    /**
     * @testdox SFD.19 TTL is calculated as dueAt timestamp + lockTtlSeconds
     */
    public function testTtlIsCalculatedFromDueAtAndLockTtlSeconds(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);

        $input = json_decode($execution['input'], true);
        $expectedTtl = $this->dueAt->getTimestamp() + 3600;
        $this->assertSame($expectedTtl, $input['ttl']);
    }

    /**
     * @testdox SFD.20 lockKey is present in payload
     */
    public function testLockKeyIsPresentInPayload(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);

        $input = json_decode($execution['input'], true);
        $this->assertArrayHasKey('lockKey', $input);
        $this->assertNotEmpty($input['lockKey']);
        // lockKey should contain the dueAt timestamp
        $this->assertStringContainsString((string) $this->dueAt->getTimestamp(), $input['lockKey']);
    }

    /**
     * @testdox SFD.21 withoutOverlapping event produces lockKey without timestamp
     */
    public function testWithoutOverlappingEventProducesLockKeyWithoutTimestamp(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');
        $event->withoutOverlapping();

        $dispatcher->dispatchEvent($event, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);

        $input = json_decode($execution['input'], true);
        $this->assertArrayHasKey('lockKey', $input);
        $this->assertNotEmpty($input['lockKey']);
        $this->assertStringNotContainsString((string) $this->dueAt->getTimestamp(), $input['lockKey']);
    }

    /**
     * @testdox SFD.22 withoutOverlapping event produces same lockKey for different dueAt
     */
    public function testWithoutOverlappingEventProducesSameLockKeyForDifferentDueAt(): void
    {
        $dispatcher = $this->createDispatcher();

        $event1 = $this->createEvent('php artisan report:daily');
        $event1->withoutOverlapping();
        $dueAt1 = new DateTimeImmutable('2024-01-15T10:00:00+09:00');

        $event2 = $this->createEvent('php artisan report:daily');
        $event2->withoutOverlapping();
        $dueAt2 = new DateTimeImmutable('2024-01-15T11:00:00+09:00');

        $dispatcher->dispatchEvent($event1, $dueAt1);
        $executions1 = $this->client->getLastExecution();

        // Reset client for second dispatch
        $this->client = new FakeStepFunctionsClient();
        $dispatcher = $this->createDispatcher();

        $dispatcher->dispatchEvent($event2, $dueAt2);
        $executions2 = $this->client->getLastExecution();

        $this->assertNotNull($executions1);
        $this->assertNotNull($executions2);

        $input1 = json_decode($executions1['input'], true);
        $input2 = json_decode($executions2['input'], true);

        $this->assertSame($input1['lockKey'], $input2['lockKey']);
    }
}
