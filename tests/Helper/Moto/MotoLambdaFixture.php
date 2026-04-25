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
 */
final class MotoLambdaFixture
{
    /** @var LambdaClient */
    private $lambda;

    /** @var IamClient */
    private $iam;

    public function __construct(LambdaClient $lambda, IamClient $iam)
    {
        $this->lambda = $lambda;
        $this->iam = $iam;
    }

    public function ensureRole(string $roleName): string
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
            throw new RuntimeException('Failed to encode the Lambda assume-role policy');
        }

        try {
            $response = $this->iam->createRole([
                'RoleName' => $roleName,
                'AssumeRolePolicyDocument' => $assumeRolePolicy,
            ]);
            return $response['Role']['Arn'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'EntityAlreadyExists') {
                throw $e;
            }
            $existing = $this->iam->getRole(['RoleName' => $roleName]);
            return $existing['Role']['Arn'];
        }
    }

    /**
     * Create a Lambda function whose handler echoes the invocation event.
     * The echo round-trip is what proves a state machine's Task input
     * reached the worker; the actual echoing is done by moto's
     * `lambda_simple` backend (see class docblock).
     */
    public function ensureEchoFunction(string $functionName, string $roleArn): void
    {
        try {
            $this->lambda->createFunction([
                'FunctionName' => $functionName,
                'Runtime' => 'python3.12',
                'Role' => $roleArn,
                'Handler' => 'index.handler',
                'Code' => ['ZipFile' => self::buildEchoZip()],
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceConflictException') {
                throw $e;
            }
        }
    }

    private static function buildEchoZip(): string
    {
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
            $zip->addFromString('index.py', "def handler(event, context):\n    return event\n");
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
