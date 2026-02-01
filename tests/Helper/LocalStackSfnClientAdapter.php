<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionAlreadyExistsException;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsException;

/**
 * LocalStack 用の SfnClient アダプター
 *
 * LocalStack は ExecutionAlreadyExists の代わりに InvalidName を返すことがあるため、
 * テスト用に InvalidName も ExecutionAlreadyExists として扱います。
 */
class LocalStackSfnClientAdapter implements StepFunctionsClientInterface
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
            /** @var \DateTimeInterface $startDate */
            $startDate = $result['startDate'];

            return new StartExecutionResult($executionArn, $startDate);
        } catch (AwsException $e) {
            // ExecutionAlreadyExists: AWS の正式なエラーコード
            // InvalidName: LocalStack が ExecutionAlreadyExists の代わりに返すケースがある
            if (in_array($e->getAwsErrorCode(), ['ExecutionAlreadyExists', 'InvalidName'], true)) {
                $name = $args['name'] ?? 'unknown';
                throw new ExecutionAlreadyExistsException($name, $e->getMessage());
            }

            throw new StepFunctionsException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
