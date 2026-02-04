<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox FailedLocalDispatchResult
 */
class FailedLocalDispatchResultTest extends TestCase
{
    /**
     * @testdox T2.23
     */
    public function testImplementsFailedDispatchResultInterface(): void
    {
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'Something went wrong');

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox T2.23
     */
    public function testIsStartedReturnsFalse(): void
    {
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'Something went wrong');

        $this->assertFalse($result->isStarted());
    }

    /**
     * @testdox T2.24
     */
    public function testStoresErrorMessage(): void
    {
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'Something went wrong');

        $this->assertSame('Something went wrong', $result->getError());
    }

    /**
     * @testdox T2.25
     */
    public function testGetDispatcherTypeReturnsLocal(): void
    {
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'error');

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox T2.35
     */
    public function testStoresEventIdentifierAndCommand(): void
    {
        $result = new FailedLocalDispatchResult('failed-event-id', 'php artisan failed:command', 'error');

        $this->assertSame('failed-event-id', $result->getEventIdentifier());
        $this->assertSame('php artisan failed:command', $result->getEventCommand());
    }

    /**
     * @testdox T2.36 stores exception when provided
     */
    public function testStoresException(): void
    {
        $exception = new \RuntimeException('Test error');
        $result = new FailedLocalDispatchResult('id', 'cmd', 'error', $exception);

        $this->assertSame($exception, $result->getException());
    }

    /**
     * @testdox T2.37 returns null exception when not provided
     */
    public function testReturnsNullExceptionWhenNotProvided(): void
    {
        $result = new FailedLocalDispatchResult('id', 'cmd', 'error');

        $this->assertNull($result->getException());
    }

    /**
     * @testdox T2.39 getDispatchedAt returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $beforeCreate = new DateTimeImmutable();
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'error');
        $afterCreate = new DateTimeImmutable();

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $dispatchedAt);
        $this->assertGreaterThanOrEqual($beforeCreate, $dispatchedAt);
        $this->assertLessThanOrEqual($afterCreate, $dispatchedAt);
    }

    /**
     * @testdox T2.41 dispatchedAt を明示的に渡した場合はその値が返される
     */
    public function testGetDispatchedAtReturnsExplicitValue(): void
    {
        $explicitTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $result = new FailedLocalDispatchResult(
            'test-id',
            'php artisan test',
            'error',
            null,
            $explicitTime
        );

        $this->assertSame($explicitTime, $result->getDispatchedAt());
    }
}
