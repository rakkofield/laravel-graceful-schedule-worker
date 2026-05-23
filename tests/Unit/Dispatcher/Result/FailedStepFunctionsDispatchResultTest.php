<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @testdox FailedStepFunctionsDispatchResult
 */
class FailedStepFunctionsDispatchResultTest extends TestCase
{
    /**
     * @testdox FSR.9 constructor is public
     */
    public function testConstructorIsPublic(): void
    {
        $constructor = new ReflectionMethod(FailedStepFunctionsDispatchResult::class, '__construct');

        $this->assertTrue($constructor->isPublic());
    }

    /**
     * @testdox FSR.1 failed() returns FailedDispatchResultInterface
     */
    public function testFailedReturnsFailedDispatchResultInterface(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = new FailedStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $exception,
            new DateTimeImmutable('2024-01-15 10:00:00'),
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertStringContainsString('Connection refused', $result->getError());
    }

    /**
     * @testdox FSR.2 getDispatcherType() returns 'stepfunctions'
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = new FailedStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $exception,
            new DateTimeImmutable('2024-01-15 10:00:00'),
            new DateTimeImmutable()
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox FSR.3 failed() with null command returns empty string
     */
    public function testFailedWithNullCommandReturnsEmptyString(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = new FailedStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            '',
            $exception,
            new DateTimeImmutable('2024-01-15 10:00:00'),
            new DateTimeImmutable()
        );

        $this->assertSame('', $result->getEventCommand());
    }

    /**
     * @testdox FSR.4 failed() with exception makes it available via getException()
     */
    public function testFailedReturnsException(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = new FailedStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $exception,
            new DateTimeImmutable('2024-01-15 10:00:00'),
            new DateTimeImmutable()
        );

        $this->assertSame($exception, $result->getException());
    }

    /**
     * @testdox FSR.6 getDispatchedAt() returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $exception = new \RuntimeException('Connection refused');
        $result = new FailedStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $exception,
            $dispatchedAt,
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result->getDispatchedAt());
        $this->assertEquals($dispatchedAt, $result->getDispatchedAt());
    }

    /**
     * @testdox FSR.7 getExecutionName() returns the Execution name
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = new FailedStepFunctionsDispatchResult(
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $exception,
            new DateTimeImmutable('2024-01-15 10:00:00'),
            new DateTimeImmutable()
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox FSR.8 getEventIdentifier() returns the event identifier
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $result = new FailedStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $exception,
            new DateTimeImmutable('2024-01-15 10:00:00'),
            new DateTimeImmutable()
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox FSR.10 getRecordedAt returns constructor value
     */
    public function testGetRecordedAtReturnsConstructorValue(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $recordedAt = new DateTimeImmutable('2024-01-15 12:00:01');
        $result = new FailedStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $exception,
            new DateTimeImmutable('2024-01-15 12:00:00'),
            $recordedAt
        );

        $this->assertSame($recordedAt, $result->getRecordedAt());
    }
}
