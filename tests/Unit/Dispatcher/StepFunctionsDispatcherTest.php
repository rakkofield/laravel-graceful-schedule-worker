<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\FakeStepFunctionsClient;
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

    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    private function createDispatcher(): StepFunctionsDispatcher
    {
        return new StepFunctionsDispatcher(
            $this->client,
            $this->stateMachineArn,
            new ExecutionNameGenerator()
        );
    }

    /**
     * @testdox SFD.1 Returns StepFunctionsDispatchResult on successful startExecution
     */
    public function testReturnsStepFunctionsDispatchResultOnSuccess(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $execution = $this->client->getLastExecution();
        $this->assertNotNull($execution);
        // Invalid characters in mutexName are sanitized
        $this->assertStringContainsString('1705282200', $execution['name']);
    }

    /**
     * @testdox SFD.4 Input JSON contains command, mutexName, and dueAt
     */
    public function testInputContainsRequiredFields(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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
     * @testdox SFD.5 getDispatcherType() returns 'stepfunctions'
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
    }

    /**
     * @testdox SFD.10 Returns correct event command
     */
    public function testReturnsCorrectEventCommand(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

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
     * @testdox SFD.14 RuntimeException propagates on json_encode failure
     */
    public function testRuntimeExceptionPropagatesOnJsonEncodeFailure(): void
    {
        $dispatcher = $this->createDispatcher();
        // Force json_encode failure with invalid UTF-8 string
        $event = $this->createEvent("\xFF\xFE");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode input JSON');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);
    }

    /**
     * @testdox SFD.15 Constructor throws InvalidArgumentException for empty stateMachineArn
     */
    public function testConstructorThrowsForEmptyStateMachineArn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stateMachineArn cannot be empty');

        new StepFunctionsDispatcher(
            $this->client,
            '',
            new ExecutionNameGenerator()
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

        // Bug: all Exceptions are caught and converted to FailedResult
        // Fix: only StepFunctionsException should be caught; others propagate
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection lost');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);
    }
}
