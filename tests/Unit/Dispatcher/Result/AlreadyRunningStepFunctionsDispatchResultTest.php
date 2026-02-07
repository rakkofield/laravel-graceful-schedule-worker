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
     * @testdox T6.1 AlreadyRunningDispatchResultInterface を実装する
     */
    public function testImplementsAlreadyRunningDispatchResultInterface(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(AlreadyRunningDispatchResultInterface::class, $result);
    }

    /**
     * @testdox T6.2 getDispatcherType() が 'stepfunctions' を返す
     */
    public function testGetDispatcherTypeReturnsStepfunctions(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox T6.3 getExecutionName() が Execution 名を返す
     */
    public function testGetExecutionNameReturnsExecutionName(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'my-execution',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('my-execution', $result->getExecutionName());
    }

    /**
     * @testdox T6.4 getEventIdentifier() がイベント識別子を返す
     */
    public function testGetEventIdentifierReturnsIdentifier(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('framework/schedule-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox T6.5 getEventCommand() がコマンドを返す
     */
    public function testGetEventCommandReturnsCommand(): void
    {
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            new DateTimeImmutable()
        );

        $this->assertSame('php artisan schedule:run', $result->getEventCommand());
    }

    /**
     * @testdox T6.6 getDispatchedAt() が DateTimeImmutable を返す
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $now = new DateTimeImmutable();
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $now
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result->getDispatchedAt());
        $this->assertSame($now, $result->getDispatchedAt());
    }

    /**
     * @testdox T6.7 カスタム dispatchedAt を指定できる
     */
    public function testAcceptsCustomDispatchedAt(): void
    {
        $customTime = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $result = new AlreadyRunningStepFunctionsDispatchResult(
            'exec-1',
            'framework/schedule-mutex',
            'php artisan schedule:run',
            $customTime
        );

        $this->assertSame($customTime, $result->getDispatchedAt());
    }
}
