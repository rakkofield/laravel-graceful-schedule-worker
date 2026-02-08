<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox StartedStepFunctionsDispatchResult
 */
class StartedStepFunctionsDispatchResultTest extends TestCase
{
    /**
     * @testdox SSR.1 StartedDispatchResultInterface を実装する
     */
    public function testImplementsStartedDispatchResultInterface(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SSR.2 getDispatcherType() が 'stepfunctions' を返す
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox SSR.3 getExecutionArn() が ARN を返す
     */
    public function testGetExecutionArnReturnsArn(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1';
        $result = new StartedStepFunctionsDispatchResult(
            $arn,
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame($arn, $result->getExecutionArn());
    }

    /**
     * @testdox SSR.4 getExecutionName() が Execution 名を返す
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:my-execution',
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox SSR.5 getEventIdentifier() がイベント識別子を返す
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox SSR.6 getEventCommand() がコマンドを返す
     */
    public function testGetEventCommandReturnsCommand(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('php artisan schedule:run', $result->getEventCommand());
    }

    /**
     * @testdox SSR.7 getDispatchedAt() が DateTimeImmutable を返す
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $now = new DateTimeImmutable();
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $now
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result->getDispatchedAt());
        $this->assertSame($now, $result->getDispatchedAt());
    }

    /**
     * @testdox SSR.8 カスタム dispatchedAt を指定できる
     */
    public function testAcceptsCustomDispatchedAt(): void
    {
        $customTime = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $customTime
        );

        $this->assertSame($customTime, $result->getDispatchedAt());
    }
}
