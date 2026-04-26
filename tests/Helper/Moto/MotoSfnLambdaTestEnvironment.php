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
 * moto's SFN parser refuses to resolve `arn:aws:states:::lambda:invoke`
 * unless the IAM role exists with a Lambda assume-role policy and the
 * Lambda function exists in the moto Lambda backend. The constructor
 * provisions both on top of the parent's SFN-side bootstrap, so a test
 * that types its env as this subclass is statically guaranteed those
 * prerequisites are in place.
 *
 * The function name is fixed because it is also hardcoded into the
 * Layer C state-machine fixture (state-machine-lambda.json); making it
 * configurable from the test would be a misleading injection point.
 */
final class MotoSfnLambdaTestEnvironment extends MotoSfnTestEnvironment
{
    private const FIXTURE_DIR = __DIR__ . '/../../StepFunctions/lambda';
    private const ASSUME_ROLE_POLICY_PATH = self::FIXTURE_DIR . '/assume-role-policy.json';
    private const ECHO_HANDLER_PATH = self::FIXTURE_DIR . '/echo_handler.py';
    private const ECHO_HANDLER_ENTRY = 'index.py';
    private const ECHO_HANDLER_TARGET = 'index.handler';
    private const PYTHON_RUNTIME = 'python3.12';

    private const LAMBDA_FUNCTION_NAME = 'graceful-scheduler-worker';
    private const LAMBDA_ROLE_NAME = 'graceful-scheduler-lambda-role';

    private function __construct(
        string $endpoint,
        string $accountId,
        string $region,
        string $stepFunctionsRoleName
    ) {
        parent::__construct($endpoint, $accountId, $region, $stepFunctionsRoleName);

        $roleArn = self::ensureRole($this->newIamClient(), self::LAMBDA_ROLE_NAME);
        self::ensureEchoFunction($this->newLambdaClient(), self::LAMBDA_FUNCTION_NAME, $roleArn);
    }

    public static function tryFromEnv(
        string $accountId,
        string $region,
        string $stepFunctionsRoleName
    ): ?self {
        $endpoint = getenv('SFN_ENDPOINT');
        if (!is_string($endpoint) || $endpoint === '') {
            return null;
        }
        return new self($endpoint, $accountId, $region, $stepFunctionsRoleName);
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
