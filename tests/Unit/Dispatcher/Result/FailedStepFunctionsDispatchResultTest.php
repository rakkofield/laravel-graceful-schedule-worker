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
     * @testdox FSR.1 failed() returns FailedDispatchResultInterface
     */
    public function testFailedReturnsFailedDispatchResultInterface(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused',
            null,
            new DateTimeImmutable('2024-01-15 10:00:00')
        );

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertSame('Connection refused', $result->getError());
    }

    /**
     * @testdox FSR.2 getDispatcherType() returns 'stepfunctions'
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused',
            null,
            new DateTimeImmutable('2024-01-15 10:00:00')
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox FSR.3 failed() with null command returns empty string
     */
    public function testFailedWithNullCommandReturnsEmptyString(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            null,
            'Connection refused',
            null,
            new DateTimeImmutable('2024-01-15 10:00:00')
        );

        $this->assertSame('', $result->getEventCommand());
    }

    /**
     * @testdox FSR.4 failed() with exception makes it available via getException()
     */
    public function testFailedReturnsException(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'RuntimeException: Connection refused',
            $exception,
            new DateTimeImmutable('2024-01-15 10:00:00')
        );

        $this->assertSame($exception, $result->getException());
    }

    /**
     * @testdox FSR.5 getException() returns null when no exception is provided
     */
    public function testFailedWithoutExceptionReturnsNull(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused',
            null,
            new DateTimeImmutable('2024-01-15 10:00:00')
        );

        $this->assertNull($result->getException());
    }

    /**
     * @testdox FSR.6 getDispatchedAt() returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused',
            null,
            $dispatchedAt
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result->getDispatchedAt());
        $this->assertEquals($dispatchedAt, $result->getDispatchedAt());
    }

    /**
     * @testdox FSR.7 getExecutionName() returns the Execution name
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused',
            null,
            new DateTimeImmutable('2024-01-15 10:00:00')
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox FSR.8 getEventIdentifier() returns the event identifier
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $result = FailedStepFunctionsDispatchResult::failed(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            'Connection refused',
            null,
            new DateTimeImmutable('2024-01-15 10:00:00')
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }
}
