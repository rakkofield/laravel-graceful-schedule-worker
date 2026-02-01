<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\StubSfnClient;

/**
 * @testdox AwsSfnClientAdapter
 */
class AwsSfnClientAdapterTest extends TestCase
{
    /**
     * @testdox T7.1 startExecution 成功時に StartExecutionResult を返す
     */
    public function testStartExecutionSuccess(): void
    {
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $executionArn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';

        $stubClient = new StubSfnClient([
            'executionArn' => $executionArn,
            'startDate' => $startDate,
        ]);

        $adapter = new AwsSfnClientAdapter($stubClient);

        $result = $adapter->startExecution([
            'stateMachineArn' => 'arn:aws:states:ap-northeast-1:123456789012:stateMachine:TestStateMachine',
            'name' => 'test-exec',
            'input' => '{}',
        ]);

        $this->assertInstanceOf(StartExecutionResult::class, $result);
        $this->assertSame($executionArn, $result->getExecutionArn());
        $this->assertEquals($startDate, $result->getStartDate());
    }

    /**
     * @testdox T7.2 ExecutionAlreadyExists エラー時に ExecutionAlreadyExistsException をスロー
     */
    public function testExecutionAlreadyExistsExceptionIsThrown(): void
    {
        $stubClient = new StubSfnClient(null, 'ExecutionAlreadyExists', 'Execution already exists');

        $adapter = new AwsSfnClientAdapter($stubClient);

        $this->expectException(ExecutionAlreadyExistsException::class);

        $adapter->startExecution([
            'stateMachineArn' => 'arn:aws:states:ap-northeast-1:123456789012:stateMachine:TestStateMachine',
            'name' => 'test-exec',
            'input' => '{}',
        ]);
    }

    /**
     * @testdox T7.3 その他の AWS エラー時に StepFunctionsException をスロー
     */
    public function testStepFunctionsExceptionIsThrownForOtherAwsErrors(): void
    {
        $stubClient = new StubSfnClient(null, 'InvalidArn', 'Invalid ARN format');

        $adapter = new AwsSfnClientAdapter($stubClient);

        $this->expectException(StepFunctionsException::class);

        $adapter->startExecution([
            'stateMachineArn' => 'invalid-arn',
            'name' => 'test-exec',
            'input' => '{}',
        ]);
    }
}
