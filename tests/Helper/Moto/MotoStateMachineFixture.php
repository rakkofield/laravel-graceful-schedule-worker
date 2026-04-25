<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Moto;

use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use RuntimeException;

/**
 * Idempotently provisions a Step Functions state machine from a JSON file
 * against any SFN-compatible endpoint (motoserver in tests, real AWS in
 * production). `StateMachineAlreadyExists` is treated as success so tests
 * can be re-run without explicit cleanup.
 */
final class MotoStateMachineFixture
{
    /** @var SfnClient */
    private $sfn;

    /** @var string */
    private $accountId;

    /** @var string */
    private $region;

    /** @var string */
    private $roleArn;

    public function __construct(SfnClient $sfn, string $accountId, string $region, string $roleArn)
    {
        $this->sfn = $sfn;
        $this->accountId = $accountId;
        $this->region = $region;
        $this->roleArn = $roleArn;
    }

    public function ensureFromFile(string $name, string $definitionPath): string
    {
        $definition = file_get_contents($definitionPath);
        if ($definition === false) {
            throw new RuntimeException('Failed to read state machine definition: ' . $definitionPath);
        }

        try {
            $this->sfn->createStateMachine([
                'name' => $name,
                'definition' => $definition,
                'roleArn' => $this->roleArn,
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'StateMachineAlreadyExists') {
                throw $e;
            }
        }

        return sprintf('arn:aws:states:%s:%s:stateMachine:%s', $this->region, $this->accountId, $name);
    }
}
