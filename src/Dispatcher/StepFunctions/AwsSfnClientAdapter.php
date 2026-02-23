<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Adapter for the AWS SDK SfnClient.
 *
 * Hides direct dependency on the AWS SDK and implements StepFunctionsClientInterface.
 */
class AwsSfnClientAdapter implements StepFunctionsClientInterface
{
    /** @var SfnClient */
    private $client;

    /** @var string */
    private $stateMachineArn;

    /**
     * @param SfnClient $client
     * @param string $stateMachineArn
     */
    public function __construct(SfnClient $client, string $stateMachineArn)
    {
        if ($stateMachineArn === '') {
            throw new InvalidArgumentException(
                'stateMachineArn cannot be empty.'
                . ' Please set graceful-scheduler.stepfunctions.state_machine_arn in your config.'
            );
        }
        $this->client = $client;
        $this->stateMachineArn = $stateMachineArn;
    }

    /**
     * {@inheritdoc}
     */
    public function startExecution(StartExecutionInput $input): StartExecutionResult
    {
        try {
            $result = $this->client->startExecution([
                'stateMachineArn' => $this->stateMachineArn,
                'name' => $input->getName(),
                'input' => $input->getInput(),
            ]);

            /** @var string $executionArn */
            $executionArn = $result['executionArn'];
            /** @var DateTimeInterface $startDate */
            $startDate = $result['startDate'];

            return new StartExecutionResult($executionArn, $startDate);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ExecutionAlreadyExists') {
                throw new ExecutionAlreadyExistsException($input->getName(), $e->getMessage());
            }

            throw new StepFunctionsException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
