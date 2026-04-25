<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use Aws\Iam\IamClient;
use Aws\Lambda\LambdaClient;
use Aws\Sfn\SfnClient;
use DateTimeImmutable;
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
 *   - E2E.2: minimal Pass state machine — payload flows through to output
 *   - E2E.3: Choice branch on payload contents
 *   - E2E.4: Lambda invoke service integration
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
     * @testdox E2E.2 LayerA: Pass state machine completes and the dispatched payload reaches the output
     */
    public function testLayerAPassStateMachineSucceeds(): void
    {
        $arn = $this->ensureStateMachine(self::STATE_MACHINE_PASS, 'state-machine.json');

        $command = 'php artisan layer-a:run-' . uniqid();
        $result = $this->dispatch($arn, $command);

        $description = $this->waitForExecutionToFinish($result->getExecutionArn());
        $this->assertSame('SUCCEEDED', $description['status']);

        $output = json_decode((string) $description['output'], true);
        $this->assertIsArray($output);
        $this->assertSame(['php', 'artisan', explode(' ', $command)[2]], $output['command']);
        $this->assertArrayHasKey('mutexName', $output);
        $this->assertArrayHasKey('lockKey', $output);
        $this->assertArrayHasKey('dueAt', $output);
        $this->assertArrayHasKey('expiresAt', $output);
    }

    /**
     * @testdox E2E.3 LayerB: Choice state takes the artisan branch when command[1]=="artisan"
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
     * @testdox E2E.4 LayerB: Choice state falls through to the default branch otherwise
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
     * @testdox E2E.5 LayerC: Task state invokes a Lambda and pipes the response back into the SFN output
     */
    public function testLayerCLambdaInvokeIntegration(): void
    {
        $arn = $this->ensureStateMachine(self::STATE_MACHINE_LAMBDA, 'state-machine-lambda.json');
        $roleArn = $this->ensureLambdaRole();
        $this->ensureLambdaFunction(self::LAMBDA_NAME, $roleArn);

        $expectedPayload = ['status' => 'ok', 'echoedAt' => $this->dueAt->format(DATE_ATOM)];
        $this->moto->queueLambdaResponses([json_encode($expectedPayload)], self::REGION, self::ACCOUNT_ID);

        $result = $this->dispatch($arn, 'php artisan layer-c:run-' . uniqid());
        $description = $this->waitForExecutionToFinish($result->getExecutionArn());

        $this->assertSame('SUCCEEDED', $description['status']);
        $output = json_decode((string) $description['output'], true);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('lambda', $output);
        $this->assertSame($expectedPayload, $output['lambda']['workerResult']);
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

        try {
            $response = $this->iamClient->createRole([
                'RoleName' => 'graceful-scheduler-lambda-role',
                'AssumeRolePolicyDocument' => $assumeRolePolicy,
            ]);
            return $response['Role']['Arn'];
        } catch (\Aws\Exception\AwsException $e) {
            if ($e->getAwsErrorCode() !== 'EntityAlreadyExists') {
                throw $e;
            }
            $existing = $this->iamClient->getRole(['RoleName' => 'graceful-scheduler-lambda-role']);
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
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('index.py', "def handler(event, context):\n    return event\n");
        $zip->close();
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
            3600
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
            $detail = method_exists($result, 'getError') ? $result->getError() : get_class($result);
            $this->fail('Dispatch did not start an execution: ' . $detail);
        }
        return $result;
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

        $this->fail(sprintf(
            'Execution %s did not finish within %dms',
            $executionArn,
            $maxAttempts * 100
        ));
    }
}
