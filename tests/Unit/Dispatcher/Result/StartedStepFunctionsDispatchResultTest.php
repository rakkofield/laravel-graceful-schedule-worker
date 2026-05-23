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
     * @testdox SSR.1 Implements StartedDispatchResultInterface
     */
    public function testImplementsStartedDispatchResultInterface(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SSR.2 getDispatcherType() returns 'stepfunctions'
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox SSR.3 getExecutionArn() returns the ARN
     */
    public function testGetExecutionArnReturnsArn(): void
    {
        $arn = 'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1';
        $result = new StartedStepFunctionsDispatchResult(
            $arn,
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame($arn, $result->getExecutionArn());
    }

    /**
     * @testdox SSR.4 getExecutionName() returns the Execution name
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:my-execution',
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox SSR.5 getEventIdentifier() returns the event identifier
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox SSR.6 getEventCommand() returns the command
     */
    public function testGetEventCommandReturnsCommand(): void
    {
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('php artisan schedule:run', $result->getEventCommand());
    }

    /**
     * @testdox SSR.7 getDispatchedAt() returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $now = new DateTimeImmutable();
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
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
     * @testdox SSR.8 Accepts custom dispatchedAt
     */
    public function testAcceptsCustomDispatchedAt(): void
    {
        $customTime = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $customTime,
            new DateTimeImmutable()
        );

        $this->assertSame($customTime, $result->getDispatchedAt());
    }

    /**
     * @testdox SSR.9 getRecordedAt returns constructor value
     */
    public function testGetRecordedAtReturnsConstructorValue(): void
    {
        $recordedAt = new DateTimeImmutable('2024-01-15 12:00:01');
        $result = new StartedStepFunctionsDispatchResult(
            'arn:aws:states:ap-northeast-1:123:execution:sm:exec-1',
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable('2024-01-15 12:00:00'),
            $recordedAt
        );

        $this->assertSame($recordedAt, $result->getRecordedAt());
    }
}
