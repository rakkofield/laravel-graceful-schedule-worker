<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox FailedLocalDispatchResult
 */
class FailedLocalDispatchResultTest extends TestCase
{
    /**
     * @testdox FLR.1 Implements FailedDispatchResultInterface
     */
    public function testImplementsFailedDispatchResultInterface(): void
    {
        $now = new DateTimeImmutable();
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'Something went wrong', null, $now);

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox FLR.2 Stores error message
     */
    public function testStoresErrorMessage(): void
    {
        $now = new DateTimeImmutable();
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'Something went wrong', null, $now);

        $this->assertSame('Something went wrong', $result->getError());
    }

    /**
     * @testdox FLR.3 getDispatcherType returns 'local'
     */
    public function testGetDispatcherTypeReturnsLocal(): void
    {
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'error', null, new DateTimeImmutable());

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox FLR.4 Stores eventIdentifier and eventCommand
     */
    public function testStoresEventIdentifierAndCommand(): void
    {
        $now = new DateTimeImmutable();
        $result = new FailedLocalDispatchResult('failed-event-id', 'php artisan failed:command', 'error', null, $now);

        $this->assertSame('failed-event-id', $result->getEventIdentifier());
        $this->assertSame('php artisan failed:command', $result->getEventCommand());
    }

    /**
     * @testdox FLR.5 stores exception when provided
     */
    public function testStoresException(): void
    {
        $exception = new \RuntimeException('Test error');
        $result = new FailedLocalDispatchResult('id', 'cmd', 'error', $exception, new DateTimeImmutable());

        $this->assertSame($exception, $result->getException());
    }

    /**
     * @testdox FLR.6 returns null exception when not provided
     */
    public function testReturnsNullExceptionWhenNotProvided(): void
    {
        $result = new FailedLocalDispatchResult('id', 'cmd', 'error', null, new DateTimeImmutable());

        $this->assertNull($result->getException());
    }

    /**
     * @testdox FLR.7 getDispatchedAt returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $now = new DateTimeImmutable();
        $result = new FailedLocalDispatchResult('test-id', 'php artisan test', 'error', null, $now);

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $dispatchedAt);
        $this->assertSame($now, $dispatchedAt);
    }

    /**
     * @testdox FLR.8 Returns explicitly provided dispatchedAt value
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
