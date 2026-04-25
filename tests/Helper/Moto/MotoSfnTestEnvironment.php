<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Moto;

use Aws\Iam\IamClient;
use Aws\Lambda\LambdaClient;
use Aws\Sfn\SfnClient;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\SfnExecutionWaiter;

/**
 * Bundles the moto-backed SFN test setup that every Step Functions
 * integration/E2E test needs: endpoint resolution from `SFN_ENDPOINT`,
 * the `execute_state_machine` config flip, the shared SfnClient,
 * `MotoStateMachineFixture`, and `SfnExecutionWaiter`.
 *
 * Lambda/IAM clients are lazy because only Layer C tests need them —
 * keeping them off the hot path saves a few ms per test.
 */
final class MotoSfnTestEnvironment
{
    /** @var string */
    private $endpoint;

    /** @var string */
    private $accountId;

    /** @var string */
    private $region;

    /** @var MotoConfigurator */
    private $moto;

    /** @var SfnClient */
    private $sfnClient;

    /** @var MotoStateMachineFixture */
    private $stateMachineFixture;

    /** @var SfnExecutionWaiter */
    private $waiter;

    private function __construct(
        string $endpoint,
        string $accountId,
        string $region,
        string $stepFunctionsRoleArn
    ) {
        $this->endpoint = $endpoint;
        $this->accountId = $accountId;
        $this->region = $region;

        $this->moto = new MotoConfigurator($endpoint);
        // moto's execute_state_machine mode hits an RLock pickling crash
        // once a state machine has been executed and another StartExecution
        // is issued (moto/stepfunctions/parser/models.py:175). Reset before
        // every test so each scenario starts from a clean backend.
        $this->moto->reset();
        $this->moto->enableStepFunctionsExecution();

        $this->sfnClient = new SfnClient(self::clientConfig($endpoint, $region));
        $this->stateMachineFixture = new MotoStateMachineFixture(
            $this->sfnClient,
            $accountId,
            $region,
            $stepFunctionsRoleArn
        );
        $this->waiter = new SfnExecutionWaiter($this->sfnClient);
    }

    /**
     * Bootstrap the environment from `SFN_ENDPOINT`. Returns null when the
     * env var is unset so the caller can decide between markTestSkipped()
     * and a hard failure.
     */
    public static function tryFromEnv(
        string $accountId,
        string $region,
        string $stepFunctionsRoleArn
    ): ?self {
        $endpoint = getenv('SFN_ENDPOINT');
        if (!is_string($endpoint) || $endpoint === '') {
            return null;
        }
        return new self($endpoint, $accountId, $region, $stepFunctionsRoleArn);
    }

    public function moto(): MotoConfigurator
    {
        return $this->moto;
    }

    public function sfnClient(): SfnClient
    {
        return $this->sfnClient;
    }

    public function stateMachineFixture(): MotoStateMachineFixture
    {
        return $this->stateMachineFixture;
    }

    public function waiter(): SfnExecutionWaiter
    {
        return $this->waiter;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function region(): string
    {
        return $this->region;
    }

    public function newLambdaClient(): LambdaClient
    {
        return new LambdaClient(self::clientConfig($this->endpoint, $this->region));
    }

    public function newIamClient(): IamClient
    {
        return new IamClient(self::clientConfig($this->endpoint, $this->region));
    }

    /**
     * @return array<string, mixed>
     */
    private static function clientConfig(string $endpoint, string $region): array
    {
        return [
            'region' => $region,
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'suppress_php_deprecation_warning' => true,
        ];
    }
}
