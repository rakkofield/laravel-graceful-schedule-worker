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
        $exception = new \RuntimeException('Something went wrong');
        $result = new FailedLocalDispatchResult(
            'test-id',
            'php artisan test',
            $exception,
            $now,
            new DateTimeImmutable()
        );

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox FLR.2 Stores error message generated from exception
     */
    public function testStoresErrorMessage(): void
    {
        $now = new DateTimeImmutable();
        $exception = new \RuntimeException('Something went wrong');
        $result = new FailedLocalDispatchResult(
            'test-id',
            'php artisan test',
            $exception,
            $now,
            new DateTimeImmutable()
        );

        $this->assertSame('RuntimeException: Something went wrong', $result->getError());
    }

    /**
     * @testdox FLR.3 getDispatcherType returns 'local'
     */
    public function testGetDispatcherTypeReturnsLocal(): void
    {
        $exception = new \RuntimeException('error');
        $result = new FailedLocalDispatchResult(
            'test-id',
            'php artisan test',
            $exception,
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox FLR.4 Stores eventIdentifier and eventCommand
     */
    public function testStoresEventIdentifierAndCommand(): void
    {
        $now = new DateTimeImmutable();
        $exception = new \RuntimeException('error');
        $result = new FailedLocalDispatchResult(
            'failed-event-id',
            'php artisan failed:command',
            $exception,
            $now,
            new DateTimeImmutable()
        );

        $this->assertSame('failed-event-id', $result->getEventIdentifier());
        $this->assertSame('php artisan failed:command', $result->getEventCommand());
    }

    /**
     * @testdox FLR.5 stores exception
     */
    public function testStoresException(): void
    {
        $exception = new \RuntimeException('Test error');
        $result = new FailedLocalDispatchResult(
            'id',
            'cmd',
            $exception,
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame($exception, $result->getException());
    }

    /**
     * @testdox FLR.7 getDispatchedAt returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $now = new DateTimeImmutable();
        $exception = new \RuntimeException('error');
        $result = new FailedLocalDispatchResult(
            'test-id',
            'php artisan test',
            $exception,
            $now,
            new DateTimeImmutable()
        );

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
        $exception = new \RuntimeException('error');
        $result = new FailedLocalDispatchResult(
            'test-id',
            'php artisan test',
            $exception,
            $explicitTime,
            new DateTimeImmutable()
        );

        $this->assertSame($explicitTime, $result->getDispatchedAt());
    }

    /**
     * @testdox FLR.9 getRecordedAt returns constructor value
     */
    public function testGetRecordedAtReturnsConstructorValue(): void
    {
        $exception = new \RuntimeException('error');
        $recordedAt = new DateTimeImmutable('2024-01-15 12:00:01');
        $result = new FailedLocalDispatchResult(
            'test-id',
            'php artisan test',
            $exception,
            new DateTimeImmutable('2024-01-15 12:00:00'),
            $recordedAt
        );

        $this->assertSame($recordedAt, $result->getRecordedAt());
    }
}
