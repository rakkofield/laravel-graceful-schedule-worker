<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use Aws\Iam\IamClient;
use Aws\Lambda\LambdaClient;
use Aws\Sfn\SfnClient;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\LockKeyGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\MutexNameSanitizer;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilder;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionInputFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Moto\MotoConfigurator;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * End-to-end tests that verify dispatched executions actually run on motoserver's
 * Step Functions emulator. Requires the moto/redis docker stack and
 * `execute_state_machine=true` enabled (handled by tests/bootstrap.php).
 *
 * Layered approach:
 *   - E2E.10 (Layer A): minimal Pass state machine — payload flows through to output
 *   - E2E.11/12 (Layer B): Choice branches on payload contents
 *   - E2E.13 (Layer C): Lambda invoke service integration
 *
 * @group e2e
 * @group stepfunctions
 */
final class StepFunctionsExecutionE2ETest extends TestCase
{
    private const ACCOUNT_ID = '000000000000';
    private const REGION = 'ap-northeast-1';
    private const ROLE_ARN = 'arn:aws:iam::000000000000:role/stepfunctions-role';
    private const LAMBDA_NAME = 'graceful-scheduler-worker';
    private const LAMBDA_ROLE_NAME = 'graceful-scheduler-lambda-role';

    private const LOCK_TTL_SECONDS = 3600;

    private const STATE_MACHINE_PASS = 'GracefulSchedulerE2EPass';
    private const STATE_MACHINE_CHOICE = 'GracefulSchedulerE2EChoice';
    private const STATE_MACHINE_LAMBDA = 'GracefulSchedulerE2ELambda';

    /** @var SfnClient */
    private $sfnClient;

    /** @var LambdaClient */
    private $lambdaClient;

    /** @var IamClient */
    private $iamClient;

    /** @var MotoConfigurator */
    private $moto;

    /** @var string */
    private $endpoint;

    /** @var Container */
    private $app;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var DateTimeImmutable */
    private $dueAt;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = getenv('SFN_ENDPOINT');
        if (!is_string($endpoint) || $endpoint === '') {
            $this->markTestSkipped('SFN_ENDPOINT is not set; motoserver required for E2E');
        }
        $this->endpoint = $endpoint;
        $this->moto = new MotoConfigurator($this->endpoint);

        // moto's execute_state_machine mode hits an RLock pickling crash once a
        // state machine has been executed and another StartExecution is issued
        // (see /moto/moto/stepfunctions/parser/models.py:175). Reset between
        // tests so each scenario starts from a clean backend.
        $this->moto->reset();
        $this->moto->enableStepFunctionsExecution();

        $clientConfig = [
            'region' => self::REGION,
            'version' => 'latest',
            'endpoint' => $this->endpoint,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'suppress_php_deprecation_warning' => true,
        ];
        $this->sfnClient = new SfnClient($clientConfig);
        $this->lambdaClient = new LambdaClient($clientConfig);
        $this->iamClient = new IamClient($clientConfig);

        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
        $this->dueAt = new DateTimeImmutable();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox E2E.10 LayerA: Pass state machine completes and the dispatched payload reaches the output
     */
    public function testLayerAPassStateMachineSucceeds(): void
    {
        $arn = $this->ensureStateMachine(self::STATE_MACHINE_PASS, 'state-machine.json');

        $taskName = 'layer-a:run-' . uniqid();
        $command = 'php artisan ' . $taskName;
        $result = $this->dispatch($arn, $command);

        $description = $this->waitForExecutionToFinish($result->getExecutionArn());
        $this->assertSame('SUCCEEDED', $description['status']);

        $output = json_decode((string) $description['output'], true);
        $this->assertIsArray($output);
        $this->assertSame(['php', 'artisan', $taskName], $output['command']);

        // Verify exact shape — these fields are this library's contract with
        // the consumer state machine. Loose key-existence checks would let a
        // PayloadBuilder regression slip through.
        $this->assertSame($this->dueAt->format(DateTimeInterface::ATOM), $output['dueAt']);
        $this->assertSame($this->dueAt->getTimestamp() + self::LOCK_TTL_SECONDS, $output['expiresAt']);
        $this->assertIsString($output['mutexName']);
        $this->assertStringStartsWith('framework/schedule-', $output['mutexName']);
        $this->assertIsString($output['lockKey']);
        $this->assertStringStartsWith('framework-schedule-', $output['lockKey']);
        $this->assertStringEndsWith('_' . $this->dueAt->getTimestamp(), $output['lockKey']);
    }

    /**
     * @testdox E2E.11 LayerB: Choice state takes the artisan branch when command[1]=="artisan"
     */
    public function testLayerBChoiceTakesArtisanBranch(): void
    {
        $arn = $this->ensureStateMachine(self::STATE_MACHINE_CHOICE, 'state-machine-choice.json');

        $result = $this->dispatch($arn, 'php artisan layer-b:artisan-' . uniqid());
        $description = $this->waitForExecutionToFinish($result->getExecutionArn());

        $this->assertSame('SUCCEEDED', $description['status']);
        $output = json_decode((string) $description['output'], true);
        $this->assertSame('artisan', $output['branch']);
    }

    /**
     * @testdox E2E.12 LayerB: Choice state falls through to the default branch otherwise
     */
    public function testLayerBChoiceFallsThroughToDefault(): void
    {
        $arn = $this->ensureStateMachine(self::STATE_MACHINE_CHOICE, 'state-machine-choice.json');

        $result = $this->dispatch($arn, '/bin/echo layer-b-' . uniqid());
        $description = $this->waitForExecutionToFinish($result->getExecutionArn());

        $this->assertSame('SUCCEEDED', $description['status']);
        $output = json_decode((string) $description['output'], true);
        $this->assertSame('other', $output['branch']);
    }

    /**
     * @testdox E2E.13 LayerC: lambda:invoke Task receives our payload and pipes the response back via ResultSelector
     */
    public function testLayerCLambdaInvokeIntegration(): void
    {
        $arn = $this->ensureStateMachine(self::STATE_MACHINE_LAMBDA, 'state-machine-lambda.json');
        $roleArn = $this->ensureLambdaRole();
        $this->ensureLambdaFunction(self::LAMBDA_NAME, $roleArn);

        // No queued response: moto's lambda_simple backend echoes the request
        // body back as the Payload, so $output.lambda.workerResult ends up
        // equal to the SFN input. That single round-trip proves both that
        // our payload reaches the Task's Lambda invocation AND that the
        // ResultSelector mapping (`Payload -> workerResult`) works.
        $taskName = 'layer-c:run-' . uniqid();
        $command = 'php artisan ' . $taskName;

        $result = $this->dispatch($arn, $command);
        $description = $this->waitForExecutionToFinish($result->getExecutionArn());

        $this->assertSame('SUCCEEDED', $description['status']);
        $output = json_decode((string) $description['output'], true);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('lambda', $output);

        $workerResult = $output['lambda']['workerResult'];
        $this->assertSame(['php', 'artisan', $taskName], $workerResult['command']);
        $this->assertSame($output['mutexName'], $workerResult['mutexName']);
        $this->assertSame($output['lockKey'], $workerResult['lockKey']);
        $this->assertSame($output['dueAt'], $workerResult['dueAt']);
    }

    private function ensureStateMachine(string $name, string $definitionFile): string
    {
        $arn = sprintf('arn:aws:states:%s:%s:stateMachine:%s', self::REGION, self::ACCOUNT_ID, $name);
        $definition = file_get_contents(__DIR__ . '/../StepFunctions/' . $definitionFile);
        if ($definition === false) {
            $this->fail('Failed to read state machine definition: ' . $definitionFile);
        }

        try {
            $this->sfnClient->createStateMachine([
                'name' => $name,
                'definition' => $definition,
                'roleArn' => self::ROLE_ARN,
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            if ($e->getAwsErrorCode() !== 'StateMachineAlreadyExists') {
                throw $e;
            }
        }

        return $arn;
    }

    private function ensureLambdaRole(): string
    {
        $assumeRolePolicy = json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'lambda.amazonaws.com'],
                'Action' => 'sts:AssumeRole',
            ]],
        ]);
        if ($assumeRolePolicy === false) {
            $this->fail('Failed to encode the Lambda assume-role policy');
        }

        try {
            $response = $this->iamClient->createRole([
                'RoleName' => self::LAMBDA_ROLE_NAME,
                'AssumeRolePolicyDocument' => $assumeRolePolicy,
            ]);
            return $response['Role']['Arn'];
        } catch (\Aws\Exception\AwsException $e) {
            if ($e->getAwsErrorCode() !== 'EntityAlreadyExists') {
                throw $e;
            }
            $existing = $this->iamClient->getRole(['RoleName' => self::LAMBDA_ROLE_NAME]);
            return $existing['Role']['Arn'];
        }
    }

    private function ensureLambdaFunction(string $name, string $roleArn): void
    {
        try {
            $this->lambdaClient->createFunction([
                'FunctionName' => $name,
                'Runtime' => 'python3.12',
                'Role' => $roleArn,
                'Handler' => 'index.handler',
                'Code' => ['ZipFile' => $this->buildLambdaZipStub()],
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceConflictException') {
                throw $e;
            }
        }
    }

    private function buildLambdaZipStub(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lambda-stub-');
        if ($tmp === false) {
            $this->fail('Failed to create a temporary file for the Lambda zip stub');
        }
        $zip = new \ZipArchive();
        // ZipArchive::open returns true on success or an int error code; without
        // the check, addFromString silently no-ops and an empty zip ships to
        // moto, surfacing as an opaque CreateFunction failure.
        $opened = $zip->open($tmp, \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tmp);
            $this->fail(sprintf('ZipArchive::open failed for %s with code %d', $tmp, (int) $opened));
        }
        $zip->addFromString('index.py', "def handler(event, context):\n    return event\n");
        if ($zip->close() !== true) {
            @unlink($tmp);
            $this->fail('ZipArchive::close failed for the Lambda zip stub');
        }
        $contents = file_get_contents($tmp);
        @unlink($tmp);
        if ($contents === false) {
            $this->fail('Failed to build Lambda zip stub');
        }
        return $contents;
    }

    private function dispatch(string $stateMachineArn, string $command): StartedDispatchResultInterface
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $sanitizer = new MutexNameSanitizer();
        $payloadBuilder = new PayloadBuilder(new LockKeyGenerator($sanitizer));
        $inputFactory = new StartExecutionInputFactory(
            new ExecutionNameGenerator($sanitizer),
            $payloadBuilder,
            self::LOCK_TTL_SECONDS
        );
        $dispatcher = new StepFunctionsDispatcher(
            new AwsSfnClientAdapter($this->sfnClient, $stateMachineArn),
            $inputFactory,
            $clock
        );

        $event = new ClockAwareEvent(
            $this->mutex,
            $command,
            $clock,
            'local',
            null,
            new TimezoneResolver()
        );
        $result = $dispatcher->dispatchEvent($event, $this->dueAt);
        if (!$result instanceof StartedDispatchResultInterface) {
            $this->fail('Dispatch did not start an execution: ' . self::describeDispatchFailure($result));
        }
        return $result;
    }

    private static function describeDispatchFailure(object $result): string
    {
        $parts = [get_class($result)];
        if (method_exists($result, 'getError')) {
            $error = $result->getError();
            $parts[] = $error instanceof \Throwable
                ? get_class($error) . ': ' . $error->getMessage()
                : (string) $error;
        }
        if (method_exists($result, 'getException')) {
            $exception = $result->getException();
            if ($exception instanceof \Throwable) {
                for ($prev = $exception->getPrevious(); $prev !== null; $prev = $prev->getPrevious()) {
                    $parts[] = 'caused by ' . get_class($prev) . ': ' . $prev->getMessage();
                }
            }
        }
        return implode(' | ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForExecutionToFinish(string $executionArn, int $maxAttempts = 50): array
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $description = $this->sfnClient->describeExecution([
                'executionArn' => $executionArn,
            ])->toArray();

            if ($description['status'] !== 'RUNNING') {
                return $description;
            }

            usleep(100000);
        }

        // Surface the last known status, error, and recent history events so a
        // permanent-RUNNING failure (often a moto bug) is debuggable.
        $final = $this->sfnClient->describeExecution(['executionArn' => $executionArn])->toArray();
        $history = $this->sfnClient->getExecutionHistory([
            'executionArn' => $executionArn,
            'maxResults' => 5,
            'reverseOrder' => true,
        ])->toArray();

        $this->fail(sprintf(
            "Execution %s did not finish within %dms. status=%s error=%s cause=%s history=%s",
            $executionArn,
            $maxAttempts * 100,
            $final['status'] ?? 'unknown',
            $final['error'] ?? '',
            $final['cause'] ?? '',
            json_encode($history['events'] ?? [])
        ));
    }
}
