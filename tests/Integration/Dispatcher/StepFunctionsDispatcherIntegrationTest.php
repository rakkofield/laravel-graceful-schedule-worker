<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Aws\DynamoDb\DynamoDbClient;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StepFunctionsDispatchResultFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\FixedExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\LockKeyGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\MutexNameSanitizer;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilder;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionInputFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Moto\MotoSfnTestEnvironment;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * StepFunctionsDispatcher integration test using moto
 *
 * @group integration
 * @group stepfunctions
 */
class StepFunctionsDispatcherIntegrationTest extends TestCase
{
    private const ACCOUNT_ID = '000000000000';
    private const REGION = 'ap-northeast-1';
    private const STATE_MACHINE_NAME = 'GracefulSchedulerStateMachine';
    private const STATE_MACHINE_LOCK_NAME = 'GracefulSchedulerStateMachineLock';
    private const STEP_FUNCTIONS_ROLE_NAME = 'stepfunctions-role';
    private const LOCK_TABLE_NAME = 'ScheduleExecutionLocks';

    /** @var string */
    private $stateMachineArn;

    /** @var MotoSfnTestEnvironment */
    private $env;

    /** @var Container */
    private $app;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var DateTimeImmutable */
    private $dueAt;

    protected function setUp(): void
    {
        parent::setUp();

        $env = MotoSfnTestEnvironment::tryFromEnv(self::ACCOUNT_ID, self::REGION, self::STEP_FUNCTIONS_ROLE_NAME);
        if ($env === null) {
            $this->markTestSkipped('SFN_ENDPOINT is not set; motoserver required for integration');
        }
        $this->env = $env;

        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
        $this->dueAt = new DateTimeImmutable();

        $this->stateMachineArn = $env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_NAME,
            __DIR__ . '/../../StepFunctions/state-machine.json'
        );
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function ensureLockTableExists(DynamoDbClient $client): void
    {
        try {
            $client->createTable([
                'TableName' => self::LOCK_TABLE_NAME,
                'KeySchema' => [
                    ['AttributeName' => 'lockKey', 'KeyType' => 'HASH'],
                ],
                'AttributeDefinitions' => [
                    ['AttributeName' => 'lockKey', 'AttributeType' => 'S'],
                ],
                'BillingMode' => 'PAY_PER_REQUEST',
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceInUseException') {
                throw $e;
            }
        }
    }

    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock, 'local', null, new TimezoneResolver());
    }

    private function createDispatcher(
        ExecutionNameGeneratorInterface $nameGenerator = null,
        string $stateMachineArn = null
    ): StepFunctionsDispatcher {
        $adapter = new AwsSfnClientAdapter(
            $this->env->sfnClient(),
            $stateMachineArn ?? $this->stateMachineArn
        );
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $sanitizer = new MutexNameSanitizer();
        $payloadBuilder = new PayloadBuilder(new LockKeyGenerator($sanitizer));
        $inputFactory = new StartExecutionInputFactory(
            $nameGenerator ?? new ExecutionNameGenerator($sanitizer),
            $payloadBuilder,
            3600
        );
        $resultFactory = new StepFunctionsDispatchResultFactory($clock);
        return new StepFunctionsDispatcher(
            $adapter,
            $inputFactory,
            $resultFactory
        );
    }

    /**
     * @testdox SFI.1 Real StartExecution API call succeeds
     */
    public function testRealStartExecutionSucceeds(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt, $this->dueAt);

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

        $result1 = $dispatcher1->dispatchEvent($event, $dueAt1, $dueAt1);
        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result1);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result1);

        // Second execution (same Execution Name but different time = different input)
        // AWS/moto behavior: same name + same input = idempotent (success)
        //                    same name + different input = ExecutionAlreadyExists
        $dueAt2 = $uniqueTime->modify('+1 second');
        $dispatcher2 = $this->createDispatcher($nameGenerator);

        $result2 = $dispatcher2->dispatchEvent($event, $dueAt2, $dueAt2);
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

        $result = $dispatcher->dispatchEvent($event, $this->dueAt, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $executionArn = $result->getExecutionArn();
        $this->assertNotNull($executionArn);

        $description = $this->env->waiter()->waitForFinish($executionArn);

        $this->assertSame('SUCCEEDED', $description['status']);
    }

    /**
     * @testdox SFI.4 StartExecution input contains dispatchedAt for AcquireLock state
     */
    public function testStartExecutionInputContainsDispatchedAt(): void
    {
        $dispatcher = $this->createDispatcher();
        $event = $this->createEvent('php artisan test:dispatched-at');

        $result = $dispatcher->dispatchEvent($event, $this->dueAt, $this->dueAt);

        $executionArn = $result->getExecutionArn();
        $this->assertNotNull($executionArn);

        $description = $this->env->sfnClient()->describeExecution([
            'executionArn' => $executionArn,
        ]);

        $input = json_decode((string) $description['input'], true);
        $this->assertArrayHasKey('dispatchedAt', $input);
        $this->assertIsInt($input['dispatchedAt']);
        $this->assertSame($result->getDispatchedAt()->getTimestamp(), $input['dispatchedAt']);
    }

    /**
     * @testdox SFI.5 AcquireLock state consumes dispatchedAt as :now end-to-end
     */
    public function testAcquireLockConsumesDispatchedAt(): void
    {
        $dynamoDb = $this->env->newDynamoDbClient();
        $this->ensureLockTableExists($dynamoDb);

        $lockStateMachineArn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_LOCK_NAME,
            __DIR__ . '/../../StepFunctions/state-machine-lock.json'
        );

        $dispatcher = $this->createDispatcher(null, $lockStateMachineArn);
        $event = $this->createEvent('php artisan test:lock-' . uniqid());

        $result = $dispatcher->dispatchEvent($event, $this->dueAt, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $executionArn = $result->getExecutionArn();
        $this->assertNotNull($executionArn);

        $description = $this->env->waiter()->waitForFinish($executionArn);

        if ($description['status'] !== 'SUCCEEDED' && self::looksLikeMotoServiceIntegrationGap($description)) {
            $this->markTestSkipped(sprintf(
                'moto declined the AcquireLock state machine (status=%s, error=%s, cause=%s)',
                $description['status'],
                $description['error'] ?? '',
                $description['cause'] ?? ''
            ));
        }

        $this->assertSame('SUCCEEDED', $description['status']);
    }

    /**
     * Recognise the small set of moto failure shapes that genuinely indicate the
     * embedded SFN runtime cannot execute DynamoDB Service Integration or the
     * JsonPath intrinsics this test exercises. Anything outside this whitelist
     * (including a vanilla `States.Runtime` failure caused by a missing
     * `dispatchedAt` field) is treated as a real regression.
     *
     * @param array{status: string, error?: string, cause?: string} $description
     */
    private static function looksLikeMotoServiceIntegrationGap(array $description): bool
    {
        $haystack = ($description['error'] ?? '') . ' ' . ($description['cause'] ?? '');
        foreach (['NotImplemented', 'Unsupported', 'Service is not yet implemented', 'NoSuchMethod'] as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
