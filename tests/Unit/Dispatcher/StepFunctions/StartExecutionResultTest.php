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
     * @testdox T6.1 getExecutionArn() が Execution ARN を返す
     */
    public function testGetExecutionArnReturnsArn(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = new StartExecutionResult($arn, $startDate);

        $this->assertSame($arn, $result->getExecutionArn());
    }

    /**
     * @testdox T6.2 getStartDate() が開始日時を返す
     */
    public function testGetStartDateReturnsStartDate(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = new StartExecutionResult($arn, $startDate);

        $this->assertSame($startDate, $result->getStartDate());
    }

    /**
     * @testdox T6.3 コンストラクタで渡された値がイミュータブルに保持される
     */
    public function testValuesAreImmutable(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123456789012:execution:TestStateMachine:test-exec';
        $startDate = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = new StartExecutionResult($arn, $startDate);

        // 2回取得しても同じ値が返る
        $this->assertSame($result->getExecutionArn(), $result->getExecutionArn());
        $this->assertSame($result->getStartDate(), $result->getStartDate());
    }
}
