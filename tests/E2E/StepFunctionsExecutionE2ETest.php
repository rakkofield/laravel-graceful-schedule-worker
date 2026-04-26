<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsTestDispatcherFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Moto\MotoLambdaFixture;
use RakkoInc\LaravelGracefulScheduleWorker\Moto\MotoSfnTestEnvironment;
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
    private const STEP_FUNCTIONS_ROLE_NAME = 'stepfunctions-role';
    private const LAMBDA_NAME = 'graceful-scheduler-worker';
    private const LAMBDA_ROLE_NAME = 'graceful-scheduler-lambda-role';

    private const LOCK_TTL_SECONDS = 3600;

    private const STATE_MACHINE_PASS = 'GracefulSchedulerE2EPass';
    private const STATE_MACHINE_CHOICE = 'GracefulSchedulerE2EChoice';
    private const STATE_MACHINE_LAMBDA = 'GracefulSchedulerE2ELambda';

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

        $env = MotoSfnTestEnvironment::tryFromEnv(
            self::ACCOUNT_ID,
            self::REGION,
            self::STEP_FUNCTIONS_ROLE_NAME
        );
        if ($env === null) {
            $this->markTestSkipped('SFN_ENDPOINT is not set; motoserver required for E2E');
        }
        $this->env = $env;

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
        $arn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_PASS,
            $this->definitionPath('state-machine.json')
        );

        $taskName = 'layer-a:run-' . uniqid();
        $command = 'php artisan ' . $taskName;
        $result = $this->dispatch($arn, $command);

        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());
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
        $arn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_CHOICE,
            $this->definitionPath('state-machine-choice.json')
        );

        $result = $this->dispatch($arn, 'php artisan layer-b:artisan-' . uniqid());
        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());

        $this->assertSame('SUCCEEDED', $description['status']);
        $output = json_decode((string) $description['output'], true);
        $this->assertSame('artisan', $output['branch']);
    }

    /**
     * @testdox E2E.12 LayerB: Choice state falls through to the default branch otherwise
     */
    public function testLayerBChoiceFallsThroughToDefault(): void
    {
        $arn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_CHOICE,
            $this->definitionPath('state-machine-choice.json')
        );

        $result = $this->dispatch($arn, '/bin/echo layer-b-' . uniqid());
        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());

        $this->assertSame('SUCCEEDED', $description['status']);
        $output = json_decode((string) $description['output'], true);
        $this->assertSame('other', $output['branch']);
    }

    /**
     * @testdox E2E.13 LayerC: lambda:invoke Task receives our payload and pipes the response back via ResultSelector
     */
    public function testLayerCLambdaInvokeIntegration(): void
    {
        $arn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_LAMBDA,
            $this->definitionPath('state-machine-lambda.json')
        );
        $roleArn = MotoLambdaFixture::ensureRole($this->env->newIamClient(), self::LAMBDA_ROLE_NAME);
        MotoLambdaFixture::ensureEchoFunction($this->env->newLambdaClient(), self::LAMBDA_NAME, $roleArn);

        // No queued response: moto's lambda_simple backend echoes the request
        // body back as the Payload, so $output.lambda.workerResult ends up
        // equal to the SFN input. That single round-trip proves both that
        // our payload reaches the Task's Lambda invocation AND that the
        // ResultSelector mapping (`Payload -> workerResult`) works.
        $taskName = 'layer-c:run-' . uniqid();
        $command = 'php artisan ' . $taskName;

        $result = $this->dispatch($arn, $command);
        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());

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

    private function dispatch(string $stateMachineArn, string $command): StartedDispatchResultInterface
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = StepFunctionsTestDispatcherFactory::create(
            $this->env->sfnClient(),
            $stateMachineArn,
            $clock,
            self::LOCK_TTL_SECONDS
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
            $this->fail(
                'Dispatch did not start an execution: '
                . StepFunctionsTestDispatcherFactory::describeFailure($result)
            );
        }
        return $result;
    }

    private function definitionPath(string $fileName): string
    {
        return __DIR__ . '/../StepFunctions/' . $fileName;
    }
}
