<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox StartExecutionResult
 */
class StartExecutionResultTest extends TestCase
{
    /**
     * @testdox SER.1 getExecutionArn() returns the Execution ARN
     */
    public function testGetExecutionArnReturnsArn(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = new StartExecutionResult($arn, $startDate);

        $this->assertSame($arn, $result->getExecutionArn());
    }

    /**
     * @testdox SER.2 getStartDate() returns the start date
     */
    public function testGetStartDateReturnsStartDate(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = new StartExecutionResult($arn, $startDate);

        $this->assertSame($startDate, $result->getStartDate());
    }

    /**
     * @testdox SER.3 Values passed via constructor are held immutably
     */
    public function testValuesAreImmutable(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = new StartExecutionResult($arn, $startDate);

        // Same value is returned even when accessed twice
        $this->assertSame($result->getExecutionArn(), $result->getExecutionArn());
        $this->assertSame($result->getStartDate(), $result->getStartDate());
    }
}
