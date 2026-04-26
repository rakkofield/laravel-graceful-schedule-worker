<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Moto;

use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\Lambda\LambdaClient;
use RuntimeException;
use ZipArchive;

/**
 * Sets up the IAM role + Lambda function pair that motoserver requires
 * before the SFN parser can resolve a `Task::lambda:invoke` target.
 *
 * moto's CreateFunction validates that the role exists in IAM and has a
 * Lambda assume-role policy (moto/awslambda/models.py:1743), so callers
 * must provision the role first. The Lambda's runtime behavior (echo vs.
 * canned) is governed by `MotoConfigurator::enableStepFunctionsExecution`,
 * which flips moto into the no-Docker `lambda_simple` backend.
 *
 * All operations are static because the helper has no state worth keeping
 * across calls — each entry point takes exactly the AWS client it needs.
 */
final class MotoLambdaFixture
{
    private const FIXTURE_DIR = __DIR__ . '/../../StepFunctions/lambda';
    private const ASSUME_ROLE_POLICY_PATH = self::FIXTURE_DIR . '/assume-role-policy.json';
    private const ECHO_HANDLER_PATH = self::FIXTURE_DIR . '/echo_handler.py';
    private const ECHO_HANDLER_ENTRY = 'index.py';
    private const ECHO_HANDLER_TARGET = 'index.handler';
    private const PYTHON_RUNTIME = 'python3.12';

    public static function ensureRole(IamClient $iam, string $roleName): string
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

    /**
     * Create a Lambda function whose handler echoes the invocation event.
     * The echo round-trip is what proves a state machine's Task input
     * reached the worker; the actual echoing is done by moto's
     * `lambda_simple` backend (see class docblock).
     */
    public static function ensureEchoFunction(LambdaClient $lambda, string $functionName, string $roleArn): void
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
