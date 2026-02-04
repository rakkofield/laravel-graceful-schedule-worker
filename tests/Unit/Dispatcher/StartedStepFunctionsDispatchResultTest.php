<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox StartedStepFunctionsDispatchResult
 */
class StartedStepFunctionsDispatchResultTest extends TestCase
{
    /**
     * @testdox T5.1 success() で isStarted が true を返す
     */
    public function testSuccessReturnsIsStartedTrue(): void
    {
        $result = StartedStepFunctionsDispatchResult::success(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertTrue($result->isStarted());
        $this->assertNull($result->getError());
        $this->assertFalse($result->wasAlreadyRunning());
    }

    /**
     * @testdox T5.2 alreadyRunning() で isStarted が true、wasAlreadyRunning が true を返す
     */
    public function testAlreadyRunningReturnsIsStartedTrueAndWasAlreadyRunningTrue(): void
    {
        $result = StartedStepFunctionsDispatchResult::alreadyRunning(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertTrue($result->isStarted());
        $this->assertNull($result->getError());
        $this->assertTrue($result->wasAlreadyRunning());
        $this->assertNull($result->getExecutionArn());
    }

    /**
     * @testdox T5.4 getDispatcherType() が 'stepfunctions' を返す
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $result = StartedStepFunctionsDispatchResult::success(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox T5.5 getExecutionArn() が成功時に ARN を返す
     */
    public function testGetExecutionArnReturnsArnOnSuccess(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1';
        $result = StartedStepFunctionsDispatchResult::success(
            $arn,
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertSame($arn, $result->getExecutionArn());
    }

    /**
     * @testdox T5.6 getExecutionName() が Execution 名を返す
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $result = StartedStepFunctionsDispatchResult::success(
            'arn:aws:states:ap-northeast-1:123:execution:sm:my-execution',
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox T5.7 getEventIdentifier() がイベント識別子を返す
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $result = StartedStepFunctionsDispatchResult::success(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox T5.8 getEventCommand() がコマンドを返す
     */
    public function testGetEventCommandReturnsCommand(): void
    {
        $result = StartedStepFunctionsDispatchResult::success(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertSame('php artisan schedule:run', $result->getEventCommand());
    }

    /**
     * @testdox T5.9 getDispatchedAt() が DateTimeImmutable を返す
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $before = new DateTimeImmutable();
        $result = StartedStepFunctionsDispatchResult::success(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );
        $after = new DateTimeImmutable();

        $this->assertInstanceOf(DateTimeImmutable::class, $result->getDispatchedAt());
        $this->assertGreaterThanOrEqual($before, $result->getDispatchedAt());
        $this->assertLessThanOrEqual($after, $result->getDispatchedAt());
    }

    /**
     * @testdox T5.11 success() で getException() が null を返す
     */
    public function testSuccessReturnsNullException(): void
    {
        $result = StartedStepFunctionsDispatchResult::success(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertNull($result->getException());
    }

    /**
     * @testdox T5.12 alreadyRunning() で getException() が null を返す
     */
    public function testAlreadyRunningReturnsNullException(): void
    {
        $result = StartedStepFunctionsDispatchResult::alreadyRunning(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run'
        );

        $this->assertNull($result->getException());
    }
}
