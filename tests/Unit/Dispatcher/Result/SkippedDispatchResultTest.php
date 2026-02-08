<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SkippedDispatchResultTest extends TestCase
{
    /**
     * @testdox SD.1 getEventIdentifier returns constructor value
     */
    public function testGetEventIdentifier(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('test-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox SD.2 getEventCommand returns constructor value
     */
    public function testGetEventCommand(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('echo test', $result->getEventCommand());
    }

    /**
     * @testdox SD.3 getReason returns constructor value
     */
    public function testGetReason(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('lock_not_acquired', $result->getReason());
    }

    /**
     * @testdox SD.4 getDispatcherType returns 'tracking'
     */
    public function testGetDispatcherType(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('tracking', $result->getDispatcherType());
    }

    /**
     * @testdox SD.5 getDispatchedAt returns constructor value
     */
    public function testGetDispatchedAtWithExplicitValue(): void
    {
        $dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', $dispatchedAt);

        $this->assertSame($dispatchedAt, $result->getDispatchedAt());
    }

    /**
     * @testdox SD.6 getDispatchedAt returns current time when omitted
     */
    public function testGetDispatchedAtWithDefaultValue(): void
    {
        $before = new DateTimeImmutable();
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());
        $after = new DateTimeImmutable();

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertGreaterThanOrEqual($before, $dispatchedAt);
        $this->assertLessThanOrEqual($after, $dispatchedAt);
    }

    /**
     * @testdox SD.7 Implements SkippedDispatchResultInterface
     */
    public function testImplementsSkippedDispatchResultInterface(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SD.8 Implements DispatchResultInterface
     */
    public function testImplementsDispatchResultInterface(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }
}
