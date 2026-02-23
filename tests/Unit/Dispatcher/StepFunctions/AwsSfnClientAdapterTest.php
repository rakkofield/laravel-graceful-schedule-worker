<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @testdox AwsSfnClientAdapter
 */
class AwsSfnClientAdapterTest extends TestCase
{
    /** @var string */
    private $stateMachineArn = 'arn:aws:states:ap-northeast-1:123456789012:stateMachine:TestStateMachine';

    /**
     * @testdox SCA.1 Returns StartExecutionResult on successful startExecution
     */
    public function testStartExecutionSuccess(): void
    {
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $executionArn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';

        $stubClient = new StubSfnClient([
            'executionArn' => $executionArn,
            'startDate' => $startDate,
        ]);

        $adapter = new AwsSfnClientAdapter($stubClient, $this->stateMachineArn);

        $result = $adapter->startExecution(
            new StartExecutionInput('test-exec', '{}')
        );

        $this->assertInstanceOf(StartExecutionResult::class, $result);
        $this->assertSame($executionArn, $result->getExecutionArn());
        $this->assertEquals($startDate, $result->getStartDate());
    }

    /**
     * @testdox SCA.2 Throws ExecutionAlreadyExistsException on ExecutionAlreadyExists error
     */
    public function testExecutionAlreadyExistsExceptionIsThrown(): void
    {
        $stubClient = new StubSfnClient(null, 'ExecutionAlreadyExists', 'Execution already exists');

        $adapter = new AwsSfnClientAdapter($stubClient, $this->stateMachineArn);

        $this->expectException(ExecutionAlreadyExistsException::class);

        $adapter->startExecution(
            new StartExecutionInput('test-exec', '{}')
        );
    }

    /**
     * @testdox SCA.3 Throws StepFunctionsException on other AWS errors
     */
    public function testStepFunctionsExceptionIsThrownForOtherAwsErrors(): void
    {
        $stubClient = new StubSfnClient(null, 'InvalidArn', 'Invalid ARN format');

        $adapter = new AwsSfnClientAdapter($stubClient, $this->stateMachineArn);

        $this->expectException(StepFunctionsException::class);

        $adapter->startExecution(
            new StartExecutionInput('test-exec', '{}')
        );
    }

    /**
     * @testdox SCA.4 Constructor throws InvalidArgumentException for empty stateMachineArn
     */
    public function testConstructorThrowsForEmptyStateMachineArn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stateMachineArn cannot be empty');

        $stubClient = new StubSfnClient([
            'executionArn' => 'arn:test',
            'startDate' => new DateTimeImmutable(),
        ]);

        new AwsSfnClientAdapter($stubClient, '');
    }

    /**
     * @testdox SCA.5 startExecution passes stateMachineArn from constructor to AWS SDK
     */
    public function testStartExecutionPassesStateMachineArnToAwsSdk(): void
    {
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $executionArn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';

        $stubClient = new StubSfnClient([
            'executionArn' => $executionArn,
            'startDate' => $startDate,
        ]);

        $adapter = new AwsSfnClientAdapter($stubClient, $this->stateMachineArn);

        $result = $adapter->startExecution(
            new StartExecutionInput('test-exec', '{"command":"test"}')
        );

        $this->assertInstanceOf(StartExecutionResult::class, $result);
    }
}
