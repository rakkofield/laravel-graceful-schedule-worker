<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Moto;

use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\Lambda\LambdaClient;
use RuntimeException;
use ZipArchive;

/**
 * Specialization of `MotoSfnTestEnvironment` for tests that exercise a
 * Lambda service integration from a Step Functions Task state.
 *
 * The constructor inherits the SFN-side bootstrap (moto reset, config
 * flip, SfnClient, state-machine fixture, waiter) and then provisions
 * the IAM role and Lambda function moto requires before the SFN parser
 * can resolve `arn:aws:states:::lambda:invoke`. The IAM trust policy
 * and Python handler live as fixture files under
 * tests/StepFunctions/lambda/ so they read as their native types.
 *
 * The type itself declares — at compile time — that callers are running
 * a Lambda-integration scenario; tests that only need plain SFN
 * execution should use the parent `MotoSfnTestEnvironment` directly.
 */
final class MotoSfnLambdaTestEnvironment extends MotoSfnTestEnvironment
{
    private const FIXTURE_DIR = __DIR__ . '/../../StepFunctions/lambda';
    private const ASSUME_ROLE_POLICY_PATH = self::FIXTURE_DIR . '/assume-role-policy.json';
    private const ECHO_HANDLER_PATH = self::FIXTURE_DIR . '/echo_handler.py';
    private const ECHO_HANDLER_ENTRY = 'index.py';
    private const ECHO_HANDLER_TARGET = 'index.handler';
    private const PYTHON_RUNTIME = 'python3.12';

    /** @var string */
    private $lambdaFunctionName;

    /** @var string */
    private $lambdaRoleArn;

    private function __construct(
        string $endpoint,
        string $accountId,
        string $region,
        string $stepFunctionsRoleName,
        string $lambdaFunctionName,
        string $lambdaRoleName
    ) {
        parent::__construct($endpoint, $accountId, $region, $stepFunctionsRoleName);

        $this->lambdaFunctionName = $lambdaFunctionName;
        $this->lambdaRoleArn = self::ensureRole($this->newIamClient(), $lambdaRoleName);
        self::ensureEchoFunction($this->newLambdaClient(), $lambdaFunctionName, $this->lambdaRoleArn);
    }

    /**
     * Bootstrap the Lambda-integration env from `SFN_ENDPOINT`. Returns
     * null when the env var is unset so the caller can decide between
     * markTestSkipped() and a hard failure. Distinct from the parent's
     * `tryFromEnv` because the Lambda variant requires more prerequisites
     * (function name + role name) — a renamed factory keeps PHP's LSP
     * signature check happy without collapsing the parent's API.
     */
    public static function tryFromEnvWithLambda(
        string $accountId,
        string $region,
        string $stepFunctionsRoleName,
        string $lambdaFunctionName,
        string $lambdaRoleName
    ): ?self {
        $endpoint = getenv('SFN_ENDPOINT');
        if (!is_string($endpoint) || $endpoint === '') {
            return null;
        }
        return new self(
            $endpoint,
            $accountId,
            $region,
            $stepFunctionsRoleName,
            $lambdaFunctionName,
            $lambdaRoleName
        );
    }

    public function lambdaFunctionName(): string
    {
        return $this->lambdaFunctionName;
    }

    public function lambdaRoleArn(): string
    {
        return $this->lambdaRoleArn;
    }

    private static function ensureRole(IamClient $iam, string $roleName): string
    {
        $assumeRolePolicy = self::readFixture(self::ASSUME_ROLE_POLICY_PATH);

        try {
            $response = $iam->createRole([
                'RoleName' => $roleName,
                'AssumeRolePolicyDocument' => $assumeRolePolicy,
            ]);
            return $response['Role']['Arn'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'EntityAlreadyExists') {
                throw $e;
            }
            $existing = $iam->getRole(['RoleName' => $roleName]);
            return $existing['Role']['Arn'];
        }
    }

    private static function ensureEchoFunction(LambdaClient $lambda, string $functionName, string $roleArn): void
    {
        try {
            $lambda->createFunction([
                'FunctionName' => $functionName,
                'Runtime' => self::PYTHON_RUNTIME,
                'Role' => $roleArn,
                'Handler' => self::ECHO_HANDLER_TARGET,
                'Code' => ['ZipFile' => self::buildEchoZip()],
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceConflictException') {
                throw $e;
            }
        }
    }

    private static function readFixture(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Failed to read fixture: ' . $path);
        }
        return $contents;
    }

    private static function buildEchoZip(): string
    {
        $handlerSource = self::readFixture(self::ECHO_HANDLER_PATH);

        $tmp = tempnam(sys_get_temp_dir(), 'lambda-stub-');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create a temporary file for the Lambda zip stub');
        }

        try {
            $zip = new ZipArchive();
            $opened = $zip->open($tmp, ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new RuntimeException(sprintf(
                    'ZipArchive::open failed for %s with code %d',
                    $tmp,
                    (int) $opened
                ));
            }
            $zip->addFromString(self::ECHO_HANDLER_ENTRY, $handlerSource);
            if ($zip->close() !== true) {
                throw new RuntimeException('ZipArchive::close failed for the Lambda zip stub');
            }

            $contents = file_get_contents($tmp);
            if ($contents === false) {
                throw new RuntimeException('Failed to read the Lambda zip stub');
            }
            return $contents;
        } finally {
            @unlink($tmp);
        }
    }
}
