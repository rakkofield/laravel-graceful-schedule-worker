<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SkippedDispatchResultTest extends TestCase
{
    /**
     * @testdox SD.1 getEventIdentifier がコンストラクタの値を返す
     */
    public function testGetEventIdentifier(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('test-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox SD.2 getEventCommand がコンストラクタの値を返す
     */
    public function testGetEventCommand(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('echo test', $result->getEventCommand());
    }

    /**
     * @testdox SD.3 getReason がコンストラクタの値を返す
     */
    public function testGetReason(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('lock_not_acquired', $result->getReason());
    }

    /**
     * @testdox SD.4 getDispatcherType は 'tracking' を返す
     */
    public function testGetDispatcherType(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertSame('tracking', $result->getDispatcherType());
    }

    /**
     * @testdox SD.5 getDispatchedAt がコンストラクタの値を返す
     */
    public function testGetDispatchedAtWithExplicitValue(): void
    {
        $dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', $dispatchedAt);

        $this->assertSame($dispatchedAt, $result->getDispatchedAt());
    }

    /**
     * @testdox SD.6 getDispatchedAt が省略時は現在時刻を返す
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
     * @testdox SD.7 SkippedDispatchResultInterface を実装している
     */
    public function testImplementsSkippedDispatchResultInterface(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SD.8 DispatchResultInterface を実装している
     */
    public function testImplementsDispatchResultInterface(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', new DateTimeImmutable());

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }
}
