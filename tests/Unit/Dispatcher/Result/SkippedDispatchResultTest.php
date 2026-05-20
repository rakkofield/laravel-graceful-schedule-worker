<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SkippedDispatchResultTest extends TestCase
{
    private function createSkippedResult(string $dispatcherType = 'local'): SkippedDispatchResult
    {
        return new SkippedDispatchResult(
            'test-mutex',
            'echo test',
            'lock_not_acquired',
            new DateTimeImmutable(),
            $dispatcherType,
            new DateTimeImmutable()
        );
    }

    /**
     * @testdox SD.1 getEventIdentifier returns constructor value
     */
    public function testGetEventIdentifier(): void
    {
        $result = $this->createSkippedResult();

        $this->assertSame('test-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox SD.2 getEventCommand returns constructor value
     */
    public function testGetEventCommand(): void
    {
        $result = $this->createSkippedResult();

        $this->assertSame('echo test', $result->getEventCommand());
    }

    /**
     * @testdox SD.3 getReason returns constructor value
     */
    public function testGetReason(): void
    {
        $result = $this->createSkippedResult();

        $this->assertSame('lock_not_acquired', $result->getReason());
    }

    /**
     * @testdox SD.4 getDispatcherType returns constructor value
     */
    public function testGetDispatcherType(): void
    {
        $result = $this->createSkippedResult();

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox SD.5 getDispatchedAt returns constructor value
     */
    public function testGetDispatchedAtWithExplicitValue(): void
    {
        $dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = new SkippedDispatchResult(
            'test-mutex',
            'echo test',
            'lock_not_acquired',
            $dispatchedAt,
            'local',
            new DateTimeImmutable()
        );

        $this->assertSame($dispatchedAt, $result->getDispatchedAt());
    }

    /**
     * @testdox SD.6 getRecordedAt returns constructor value
     */
    public function testGetRecordedAt(): void
    {
        $recordedAt = new DateTimeImmutable('2024-01-15 10:00:01');
        $result = new SkippedDispatchResult(
            'test-mutex',
            'echo test',
            'lock_not_acquired',
            new DateTimeImmutable('2024-01-15 10:00:00'),
            'local',
            $recordedAt
        );

        $this->assertSame($recordedAt, $result->getRecordedAt());
    }

    /**
     * @testdox SD.7 Implements SkippedDispatchResultInterface
     */
    public function testImplementsSkippedDispatchResultInterface(): void
    {
        $result = $this->createSkippedResult();

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SD.8 Implements DispatchResultInterface
     */
    public function testImplementsDispatchResultInterface(): void
    {
        $result = $this->createSkippedResult();

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox SD.9 getDispatcherType returns constructor value
     */
    public function testGetDispatcherTypeReturnsConstructorValue(): void
    {
        $result = $this->createSkippedResult('stepfunctions');

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }
}
