<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;

/**
 * AWS SDK SfnClient のアダプター
 *
 * AWS SDK への直接依存を隠蔽し、StepFunctionsClientInterface を実装します。
 */
class AwsSfnClientAdapter implements StepFunctionsClientInterface
{
    /** @var SfnClient */
    private $client;

    /**
     * @param SfnClient $client
     */
    public function __construct(SfnClient $client)
    {
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function startExecution(array $args): array
    {
        try {
            $result = $this->client->startExecution($args);

            return [
                'executionArn' => $result['executionArn'],
                'startDate' => $result['startDate'],
            ];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ExecutionAlreadyExists') {
                $name = isset($args['name']) ? $args['name'] : 'unknown';
                throw new ExecutionAlreadyExistsException($name, $e->getMessage());
            }

            throw new StepFunctionsException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
