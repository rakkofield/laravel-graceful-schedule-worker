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
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsTimeoutSettings;
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
    private const STATE_MACHINE_TIMEOUT_PATH_NAME = 'GracefulSchedulerStateMachineTimeoutPath';
    private const STEP_FUNCTIONS_ROLE_NAME = 'stepfunctions-role';
    private const LOCK_TABLE_NAME = 'ScheduleExecutionLocks';
    private const PREEXISTING_LOCK_HOLDER = 'arn:aws:states:preexisting-holder';

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
     * @testdox SFI.6 AcquireLock diverts a duplicate run to AlreadyRunning while a non-expired lock is held
     */
    public function testAcquireLockRejectsDuplicateWhileLockHeld(): void
    {
        $dynamoDb = $this->env->newDynamoDbClient();
        $this->ensureLockTableExists($dynamoDb);

        $lockStateMachineArn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_LOCK_NAME,
            __DIR__ . '/../../StepFunctions/state-machine-lock.json'
        );

        $event = $this->createEvent('php artisan test:dup-held-' . uniqid());
        $lockKey = $this->lockKeyFor($event, $this->dueAt);

        // Simulate a previous run that is still executing: its lock is held and
        // expires far in the future (expiresAt > dispatchedAt), so the guard
        // "attribute_not_exists(lockKey) OR expiresAt < :now" is false.
        $this->seedLock($dynamoDb, $lockKey, $this->dueAt->getTimestamp() + 100000);

        $dispatcher = $this->createDispatcher(null, $lockStateMachineArn);
        $result = $dispatcher->dispatchEvent($event, $this->dueAt, $this->dueAt);

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

        $entered = $this->enteredStates($executionArn);
        $this->assertContains('AlreadyRunning', $entered, 'a duplicate run must divert to AlreadyRunning');
        $this->assertNotContains('ExecuteTask', $entered, 'a duplicate run must NOT execute the task');

        // The original holder still owns the lock: the duplicate neither stole nor released it.
        $held = $dynamoDb->getItem([
            'TableName' => self::LOCK_TABLE_NAME,
            'Key' => ['lockKey' => ['S' => $lockKey]],
        ]);
        $this->assertSame(
            self::PREEXISTING_LOCK_HOLDER,
            $held['Item']['executionArn']['S'] ?? null,
            'the pre-existing lock must remain owned by the original holder'
        );
    }

    /**
     * @testdox SFI.7 AcquireLock reclaims an expired lock and runs the task (TTL steal)
     */
    public function testAcquireLockReclaimsExpiredLock(): void
    {
        $dynamoDb = $this->env->newDynamoDbClient();
        $this->ensureLockTableExists($dynamoDb);

        $lockStateMachineArn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_LOCK_NAME,
            __DIR__ . '/../../StepFunctions/state-machine-lock.json'
        );

        $event = $this->createEvent('php artisan test:dup-expired-' . uniqid());
        $lockKey = $this->lockKeyFor($event, $this->dueAt);

        // Simulate a previous run whose lock has already expired
        // (expiresAt < dispatchedAt), so the guard clause "expiresAt < :now" is true.
        $this->seedLock($dynamoDb, $lockKey, $this->dueAt->getTimestamp() - 10);

        $dispatcher = $this->createDispatcher(null, $lockStateMachineArn);
        $result = $dispatcher->dispatchEvent($event, $this->dueAt, $this->dueAt);

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

        $entered = $this->enteredStates($executionArn);
        $this->assertContains('ExecuteTask', $entered, 'an expired lock must be reclaimed and the task executed');
        $this->assertNotContains('AlreadyRunning', $entered, 'an expired lock must not divert to AlreadyRunning');
    }

    /**
     * @testdox SFI.8 A normal event overlaps across slots: a later slot runs while the earlier lock is held
     */
    public function testNormalEventDoesNotDedupeAcrossSlots(): void
    {
        $dynamoDb = $this->env->newDynamoDbClient();
        $this->ensureLockTableExists($dynamoDb);

        $lockStateMachineArn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_LOCK_NAME,
            __DIR__ . '/../../StepFunctions/state-machine-lock.json'
        );

        // A normal (no withoutOverlapping) event, dispatched for two different slots.
        $event = $this->createEvent('php artisan test:no-overlap-flag-' . uniqid());
        $slot1 = new DateTimeImmutable('2026-07-25 10:00:00');
        $slot2 = new DateTimeImmutable('2026-07-25 10:10:00');

        // Per-slot lockKeys differ because the timestamp is part of the key.
        $this->assertNotSame(
            $this->lockKeyFor($event, $slot1),
            $this->lockKeyFor($event, $slot2),
            'normal events must produce a distinct lockKey per slot'
        );

        // The first slot is still running and holds its lock (not expired).
        $this->seedLock($dynamoDb, $this->lockKeyFor($event, $slot1), $slot2->getTimestamp() + 100000);

        // Dispatch the second slot: it uses a different lockKey, so AcquireLock succeeds.
        $dispatcher = $this->createDispatcher(null, $lockStateMachineArn);
        $result = $dispatcher->dispatchEvent($event, $slot2, $slot2);

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

        // The second slot runs concurrently with the first: cross-slot dedup does NOT happen.
        $entered = $this->enteredStates($executionArn);
        $this->assertContains(
            'ExecuteTask',
            $entered,
            'a normal event overlaps across slots because each slot uses a distinct lockKey'
        );
    }

    /**
     * @testdox SFI.9 withoutOverlapping blocks a later slot while an earlier slot's lock is held (cross-slot dedup)
     */
    public function testWithoutOverlappingDedupesAcrossSlots(): void
    {
        $dynamoDb = $this->env->newDynamoDbClient();
        $this->ensureLockTableExists($dynamoDb);

        $lockStateMachineArn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_LOCK_NAME,
            __DIR__ . '/../../StepFunctions/state-machine-lock.json'
        );

        // Same event, but declared withoutOverlapping, dispatched for two different slots.
        $event = $this->createEvent('php artisan test:with-overlap-flag-' . uniqid());
        $event->withoutOverlapping();
        $slot1 = new DateTimeImmutable('2026-07-25 10:00:00');
        $slot2 = new DateTimeImmutable('2026-07-25 10:10:00');

        // The lockKey is stable across slots (no timestamp), so the two slots collide.
        $this->assertSame(
            $this->lockKeyFor($event, $slot1),
            $this->lockKeyFor($event, $slot2),
            'withoutOverlapping events must produce a stable lockKey across slots'
        );

        // The first slot is still running and holds its lock (not expired).
        $this->seedLock($dynamoDb, $this->lockKeyFor($event, $slot1), $slot2->getTimestamp() + 100000);

        // Dispatch the second slot: it shares the lockKey, so AcquireLock is rejected.
        $dispatcher = $this->createDispatcher(null, $lockStateMachineArn);
        $result = $dispatcher->dispatchEvent($event, $slot2, $slot2);

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

        // The second slot is blocked: cross-slot dedup works.
        $entered = $this->enteredStates($executionArn);
        $this->assertContains('AlreadyRunning', $entered, 'withoutOverlapping must block the overlapping slot');
        $this->assertNotContains('ExecuteTask', $entered, 'the blocked slot must not execute the task');
    }

    /**
     * @testdox SFI.10 A Task state taking its timeout from the payload (TimeoutSecondsPath) is accepted
     */
    public function testStateMachineWithTimeoutSecondsPathIsAccepted(): void
    {
        // CreateStateMachine validates the ASL, so a definition that reads the
        // Task timeout from our payload field being accepted is what this asserts.
        // Whether the resolved value actually bounds the run is AWS behaviour and
        // cannot be exercised here: moto cannot run the ECS integration.
        $arn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_TIMEOUT_PATH_NAME,
            __DIR__ . '/../../StepFunctions/state-machine-timeout-path.json'
        );

        $described = $this->env->sfnClient()->describeStateMachine(['stateMachineArn' => $arn]);
        $definition = json_decode((string) $described['definition'], true);

        $this->assertSame(
            '$.timeoutSeconds',
            $definition['States']['InvokeWorker']['TimeoutSecondsPath'] ?? null
        );
        $this->assertArrayNotHasKey(
            'TimeoutSeconds',
            $definition['States']['InvokeWorker'],
            'TimeoutSeconds and TimeoutSecondsPath are mutually exclusive'
        );

        // The path the definition points at must exist in what we actually dispatch.
        $dispatcher = $this->createDispatcher(null, $arn);
        $event = $this->createEvent('php artisan test:timeout-path-' . uniqid());
        $result = $dispatcher->dispatchEvent($event, $this->dueAt, $this->dueAt);

        $executionArn = $result->getExecutionArn();
        $this->assertNotNull($executionArn);

        $description = $this->env->sfnClient()->describeExecution(['executionArn' => $executionArn]);
        $input = json_decode((string) $description['input'], true);

        // Exact, not just positive: createDispatcher() hardcodes lockTtl 3600 and this
        // dispatch has dispatchedAt == dueAt, so the derived value is deterministic.
        // Asserting only "> 0" would pass even if the derivation were replaced wholesale.
        $this->assertSame(
            3600 - StepFunctionsTimeoutSettings::DEFAULT_LOCK_RELEASE_BUFFER,
            $input['timeoutSeconds']
        );
    }

    /**
     * Compute the exact lockKey the dispatcher will send for the given event/dueAt.
     */
    private function lockKeyFor(ClockAwareEvent $event, DateTimeImmutable $dueAt): string
    {
        return (new LockKeyGenerator(new MutexNameSanitizer()))->generate($event, $dueAt);
    }

    /**
     * Seed the lock table with a pre-existing lock owned by another execution.
     */
    private function seedLock(DynamoDbClient $client, string $lockKey, int $expiresAt): void
    {
        $client->putItem([
            'TableName' => self::LOCK_TABLE_NAME,
            'Item' => [
                'lockKey' => ['S' => $lockKey],
                'executionArn' => ['S' => self::PREEXISTING_LOCK_HOLDER],
                'expiresAt' => ['N' => (string) $expiresAt],
            ],
        ]);
    }

    /**
     * Names of the states entered during the execution, in order.
     *
     * @return string[]
     */
    private function enteredStates(string $executionArn): array
    {
        $history = $this->env->sfnClient()->getExecutionHistory([
            'executionArn' => $executionArn,
            'maxResults' => 100,
        ]);

        $states = [];
        foreach ($history['events'] as $event) {
            if (isset($event['stateEnteredEventDetails']['name'])) {
                $states[] = $event['stateEnteredEventDetails']['name'];
            }
        }
        return $states;
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
