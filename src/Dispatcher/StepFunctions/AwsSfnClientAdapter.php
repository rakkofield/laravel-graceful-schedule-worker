<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use DateTimeInterface;

/**
 * Adapter for the AWS SDK SfnClient.
 *
 * Hides direct dependency on the AWS SDK and implements StepFunctionsClientInterface.
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
    public function startExecution(array $args): StartExecutionResult
    {
        try {
            $result = $this->client->startExecution($args);

            /** @var string $executionArn */
            $executionArn = $result['executionArn'];
            /** @var DateTimeInterface $startDate */
            $startDate = $result['startDate'];

            return new StartExecutionResult($executionArn, $startDate);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ExecutionAlreadyExists') {
                $name = $args['name'] ?? 'unknown';
                throw new ExecutionAlreadyExistsException($name, $e->getMessage());
            }

            throw new StepFunctionsException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
