<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExpectedSfnOutput;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\Payload;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsTestDispatcherFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Moto\MotoSfnLambdaTestEnvironment;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * End-to-end test for the Lambda service-integration scenario: a state
 * machine whose Task state invokes a Lambda function and pipes the
 * response back into the SFN output via ResultSelector.
 *
 * The Lambda + IAM provisioning lives in `MotoSfnLambdaTestEnvironment`
 * so the test method body shrinks to "create the state machine,
 * dispatch, wait, assert" — the prerequisites are declared at the env
 * type rather than orchestrated mid-test.
 *
 * @group e2e
 * @group stepfunctions
 */
final class StepFunctionsLambdaTaskE2ETest extends TestCase
{
    private const ACCOUNT_ID = '000000000000';
    private const REGION = 'ap-northeast-1';
    private const STEP_FUNCTIONS_ROLE_NAME = 'stepfunctions-role';

    private const LOCK_TTL_SECONDS = 3600;
    private const DISPATCH_INSTANT = StepFunctionsTestDispatcherFactory::DEFAULT_DISPATCH_INSTANT;

    private const STATE_MACHINE_LAMBDA = 'GracefulSchedulerE2ELambda';

    /** @var MotoSfnLambdaTestEnvironment */
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

        $env = MotoSfnLambdaTestEnvironment::tryFromEnv(
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
     * @testdox E2E.13 LayerC: lambda:invoke Task receives our payload and pipes the response back via ResultSelector
     */
    public function testLambdaInvokeIntegration(): void
    {
        $arn = $this->env->stateMachineFixture()->ensureFromFile(
            self::STATE_MACHINE_LAMBDA,
            $this->definitionPath('state-machine-lambda.json')
        );

        // moto's lambda_simple backend echoes the request body back as the
        // Payload, so the Task's ResultSelector lands the dispatched
        // payload under `lambda.workerResult`. Asserting against
        // ExpectedSfnOutput::lambdaEchoOf in one shot proves both that
        // our payload reached the Lambda invocation AND that the
        // ResultSelector mapping is intact.
        $command = 'php artisan layer-c:run-' . uniqid();
        $result = $this->dispatch($arn, $command);
        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());

        $expected = $this->expectedPayload($command);
        $this->assertSame('SUCCEEDED', $description['status']);
        $this->assertJsonStringEqualsJsonString(
            ExpectedSfnOutput::lambdaEchoOf($expected),
            (string) $description['output']
        );
    }

    private function dispatch(string $stateMachineArn, string $command): StartedDispatchResultInterface
    {
        $clock = new FixedClock(new DateTimeImmutable(self::DISPATCH_INSTANT));
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
        $result = $dispatcher->dispatchEvent($event, $this->dueAt, new DateTimeImmutable(self::DISPATCH_INSTANT));
        if (!$result instanceof StartedDispatchResultInterface) {
            $this->fail(
                'Dispatch did not start an execution: '
                . StepFunctionsTestDispatcherFactory::describeFailure($result)
            );
        }
        return $result;
    }

    private function expectedPayload(string $command): Payload
    {
        return StepFunctionsTestDispatcherFactory::buildExpectedPayload(
            $this->mutex,
            $command,
            $this->dueAt,
            self::LOCK_TTL_SECONDS,
            new DateTimeImmutable(self::DISPATCH_INSTANT)
        );
    }

    private function definitionPath(string $fileName): string
    {
        return __DIR__ . '/../StepFunctions/' . $fileName;
    }
}
