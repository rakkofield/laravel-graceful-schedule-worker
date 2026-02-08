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
 * StepFunctionsDispatcher integration test using moto
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

        // Use SFN_ENDPOINT (set in phpunit.xml.dist)
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
     * @testdox SFI.1 Real StartExecution API call succeeds
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
     * @testdox SFI.2 Second call with the same Execution Name returns ExecutionAlreadyExists
     */
    public function testDuplicateExecutionReturnsAlreadyRunning(): void
    {
        // Use a unique Execution Name for each test run
        $uniqueTime = new DateTimeImmutable();
        $fixedName = 'test-duplicate-' . $uniqueTime->format('U-u');
        $nameGenerator = new FixedExecutionNameGenerator($fixedName);

        // First execution
        $dueAt1 = $uniqueTime;
        $dispatcher1 = $this->createDispatcher($nameGenerator);
        $uniqueCommand = 'php artisan test:duplicate-' . $uniqueTime->format('U.u');
        $event = $this->createEvent($uniqueCommand);

        $result1 = $dispatcher1->dispatchEvent($event, $this->app, $dueAt1);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result1);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result1);

        // Second execution (same Execution Name but different time = different input)
        // AWS/moto behavior: same name + same input = idempotent (success)
        //                    same name + different input = ExecutionAlreadyExists
        $dueAt2 = $uniqueTime->modify('+1 second');
        $dispatcher2 = $this->createDispatcher($nameGenerator);

        $result2 = $dispatcher2->dispatchEvent($event, $this->app, $dueAt2);
        $this->assertInstanceOf(AlreadyRunningDispatchResultInterface::class, $result2);
        $this->assertInstanceOf(AlreadyRunningStepFunctionsDispatchResult::class, $result2);
    }

    /**
     * @testdox SFI.3 State Machine actually executes
     */
    public function testStateMachineActuallyExecutes(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan test:execution');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $executionArn = $result->getExecutionArn();
        $this->assertNotNull($executionArn);

        // Verify execution status
        $description = $this->sfnClient->describeExecution([
            'executionArn' => $executionArn,
        ]);

        // It's a Pass State, so it completes almost immediately
        $this->assertContains($description['status'], ['RUNNING', 'SUCCEEDED']);
    }
}
