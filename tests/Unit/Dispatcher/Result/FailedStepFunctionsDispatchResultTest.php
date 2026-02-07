<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox FailedStepFunctionsDispatchResult
 */
class FailedStepFunctionsDispatchResultTest extends TestCase
{
    /**
     * @testdox T5.3 failed() で FailedDispatchResultInterface を返す
     */
    public function testFailedReturnsFailedDispatchResultInterface(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused'
        );

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertSame('Connection refused', $result->getError());
    }

    /**
     * @testdox T5.4 getDispatcherType() が 'stepfunctions' を返す
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused'
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox T5.10 failed() で command が null の場合は空文字列になる
     */
    public function testFailedWithNullCommandReturnsEmptyString(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            null,
            'Connection refused'
        );

        $this->assertSame('', $result->getEventCommand());
    }

    /**
     * @testdox T5.13 failed() で例外を渡すと getException() で取得できる
     */
    public function testFailedReturnsException(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'RuntimeException: Connection refused',
            $exception
        );

        $this->assertSame($exception, $result->getException());
    }

    /**
     * @testdox T5.14 failed() で例外を渡さない場合は getException() が null を返す
     */
    public function testFailedWithoutExceptionReturnsNull(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused'
        );

        $this->assertNull($result->getException());
    }

    /**
     * @testdox T5.15 getDispatchedAt() が DateTimeImmutable を返す
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $before = new DateTimeImmutable();
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused'
        );
        $after = new DateTimeImmutable();

        $this->assertInstanceOf(DateTimeImmutable::class, $result->getDispatchedAt());
        $this->assertGreaterThanOrEqual($before, $result->getDispatchedAt());
        $this->assertLessThanOrEqual($after, $result->getDispatchedAt());
    }

    /**
     * @testdox T5.16 getExecutionName() が Execution 名を返す
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused'
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox T5.17 getEventIdentifier() がイベント識別子を返す
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused'
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }
}
