<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SkippedDispatchResultTest extends TestCase
{
    /**
     * @testdox SD1.1 getEventIdentifier がコンストラクタの値を返す
     */
    public function testGetEventIdentifier(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired');

        $this->assertSame('test-mutex', $result->getEventIdentifier());
    }

    /**
     * @testdox SD1.2 getEventCommand がコンストラクタの値を返す
     */
    public function testGetEventCommand(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired');

        $this->assertSame('echo test', $result->getEventCommand());
    }

    /**
     * @testdox SD1.3 getReason がコンストラクタの値を返す
     */
    public function testGetReason(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired');

        $this->assertSame('lock_not_acquired', $result->getReason());
    }

    /**
     * @testdox SD1.4 getDispatcherType は 'tracking' を返す
     */
    public function testGetDispatcherType(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired');

        $this->assertSame('tracking', $result->getDispatcherType());
    }

    /**
     * @testdox SD1.5 getDispatchedAt がコンストラクタの値を返す
     */
    public function testGetDispatchedAtWithExplicitValue(): void
    {
        $dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired', $dispatchedAt);

        $this->assertSame($dispatchedAt, $result->getDispatchedAt());
    }

    /**
     * @testdox SD1.6 getDispatchedAt が省略時は現在時刻を返す
     */
    public function testGetDispatchedAtWithDefaultValue(): void
    {
        $before = new DateTimeImmutable();
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired');
        $after = new DateTimeImmutable();

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertGreaterThanOrEqual($before, $dispatchedAt);
        $this->assertLessThanOrEqual($after, $dispatchedAt);
    }

    /**
     * @testdox SD1.7 SkippedDispatchResultInterface を実装している
     */
    public function testImplementsSkippedDispatchResultInterface(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired');

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox SD1.8 DispatchResultInterface を実装している
     */
    public function testImplementsDispatchResultInterface(): void
    {
        $result = new SkippedDispatchResult('test-mutex', 'echo test', 'lock_not_acquired');

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }
}
