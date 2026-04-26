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
use RakkoInc\LaravelGracefulScheduleWorker\Moto\MotoSfnTestEnvironment;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * End-to-end tests that verify dispatched executions reach an
 * internal-only state machine and that the dispatched payload flows
 * through the execution. Cross-service integrations (Lambda, etc.) are
 * covered by sibling test classes.
 *
 * Layered approach:
 *   - E2E.10 (Layer A): minimal Pass state machine — payload flows through to output
 *   - E2E.11/12 (Layer B): Choice branches on payload contents
 *
 * @group e2e
 * @group stepfunctions
 */
final class StepFunctionsExecutionE2ETest extends TestCase
{
    private const ACCOUNT_ID = '000000000000';
    private const REGION = 'ap-northeast-1';
    private const STEP_FUNCTIONS_ROLE_NAME = 'stepfunctions-role';

    private const LOCK_TTL_SECONDS = 3600;

    private const STATE_MACHINE_PASS = 'GracefulSchedulerE2EPass';
    private const STATE_MACHINE_CHOICE = 'GracefulSchedulerE2EChoice';

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

        $command = 'php artisan layer-a:run-' . uniqid();
        $result = $this->dispatch($arn, $command);
        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());

        $expected = $this->expectedPayload($command);
        $this->assertSame('SUCCEEDED', $description['status']);
        $this->assertJsonStringEqualsJsonString(
            ExpectedSfnOutput::passThroughOf($expected),
            (string) $description['output']
        );
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

        $command = 'php artisan layer-b:artisan-' . uniqid();
        $result = $this->dispatch($arn, $command);
        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());

        $expected = $this->expectedPayload($command);
        $this->assertSame('SUCCEEDED', $description['status']);
        $this->assertJsonStringEqualsJsonString(
            ExpectedSfnOutput::choiceBranch($expected, 'artisan'),
            (string) $description['output']
        );
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

        $command = '/bin/echo layer-b-' . uniqid();
        $result = $this->dispatch($arn, $command);
        $description = $this->env->waiter()->waitForFinish($result->getExecutionArn());

        $expected = $this->expectedPayload($command);
        $this->assertSame('SUCCEEDED', $description['status']);
        $this->assertJsonStringEqualsJsonString(
            ExpectedSfnOutput::choiceBranch($expected, 'other'),
            (string) $description['output']
        );
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

    private function expectedPayload(string $command): Payload
    {
        return StepFunctionsTestDispatcherFactory::buildExpectedPayload(
            $this->mutex,
            $command,
            $this->dueAt,
            self::LOCK_TTL_SECONDS
        );
    }

    private function definitionPath(string $fileName): string
    {
        return __DIR__ . '/../StepFunctions/' . $fileName;
    }
}
