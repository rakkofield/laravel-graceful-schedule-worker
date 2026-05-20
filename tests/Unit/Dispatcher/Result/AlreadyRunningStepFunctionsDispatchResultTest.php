<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox AlreadyRunningStepFunctionsDispatchResult
 */
class AlreadyRunningStepFunctionsDispatchResultTest extends TestCase
{
    /**
     * @testdox ARR.1 Implements AlreadyRunningDispatchResultInterface
     */
    public function testImplementsAlreadyRunningDispatchResultInterface(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(AlreadyRunningDispatchResultInterface::class, $result);
    }

    /**
     * @testdox ARR.2 getDispatcherType() returns 'stepfunctions'
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox ARR.3 getExecutionName() returns the Execution name
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox ARR.4 getEventIdentifier() returns the event identifier
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox ARR.5 getEventCommand() returns the command
     */
    public function testGetEventCommandReturnsCommand(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('php artisan schedule:run', $result->getEventCommand());
    }

    /**
     * @testdox ARR.6 getDispatchedAt() returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $now = new DateTimeImmutable();
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $now,
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result->getDispatchedAt());
        $this->assertSame($now, $result->getDispatchedAt());
    }

    /**
     * @testdox ARR.7 Accepts custom dispatchedAt
     */
    public function testAcceptsCustomDispatchedAt(): void
    {
        $customTime = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $customTime,
            new DateTimeImmutable()
        );

        $this->assertSame($customTime, $result->getDispatchedAt());
    }

    /**
     * @testdox ARR.8 getRecordedAt returns constructor value
     */
    public function testGetRecordedAtReturnsConstructorValue(): void
    {
        $recordedAt = new DateTimeImmutable('2024-01-15 12:00:01');
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable('2024-01-15 12:00:00'),
            $recordedAt
        );

        $this->assertSame($recordedAt, $result->getRecordedAt());
    }
}
