<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Scheduling;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

class ClockAwareEventTest extends TestCase
{
    /**
     * @var EventMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutex = $this->createMock(EventMutex::class);
    }

    /**
     * T1.4: ClockAwareEvent は Clock を注入できる
     *
     * @test
     */
    public function it_can_inject_clock()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * T1.5: withGracePeriod() で猶予期間を設定できる
     *
     * @test
     */
    public function it_can_set_grace_period()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $result = $event->withGracePeriod(30);

        $this->assertSame($event, $result, 'withGracePeriod should return self for method chaining');
    }

    /**
     * T1.6: enableRecovery() でリカバリを有効化できる
     *
     * @test
     */
    public function it_can_enable_recovery()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $result = $event->enableRecovery();

        $this->assertSame($event, $result, 'enableRecovery should return self for method chaining');
    }

    /**
     * T1.7: getCurrentTime() は Clock.now() を返す
     *
     * @test
     */
    public function it_returns_current_time_from_clock()
    {
        $fixedTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $clock = new FixedClock($fixedTime);
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $currentTime = $event->getCurrentTime();

        $this->assertEquals($fixedTime, $currentTime);
    }

    /**
     * getCurrentTime() を複数回呼んでも同じ時刻を返す（FixedClock使用時）
     *
     * @test
     */
    public function it_returns_same_time_on_multiple_calls_with_fixed_clock()
    {
        $fixedTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $clock = new FixedClock($fixedTime);
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $time1 = $event->getCurrentTime();
        $time2 = $event->getCurrentTime();

        $this->assertEquals($time1->getTimestamp(), $time2->getTimestamp());
    }

    /**
     * デフォルトではリカバリが無効
     *
     * @test
     */
    public function recoverable_is_false_by_default()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $this->assertFalse($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * T1.5拡張: withGracePeriod() で recoverable が true になる
     *
     * @test
     */
    public function withGracePeriod_sets_recoverable_to_true()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->withGracePeriod(30);

        $this->assertTrue($event->isRecoverable());
    }

    /**
     * T1.6拡張: withGracePeriod() で正しい猶予期間が設定される
     *
     * @test
     */
    public function withGracePeriod_sets_correct_interval()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->withGracePeriod(30);

        $gracePeriod = $event->getGracePeriod();
        $this->assertInstanceOf(DateInterval::class, $gracePeriod);

        // DateInterval を実際の時間差に変換して検証
        $now = new DateTimeImmutable('2024-01-01 12:00:00');
        $later = $now->add($gracePeriod);
        $diff = $later->getTimestamp() - $now->getTimestamp();

        $this->assertEquals(30 * 60, $diff, '30分 = 1800秒');
    }

    /**
     * withGracePeriod(null) で無制限猶予期間になる
     *
     * @test
     */
    public function withGracePeriod_with_null_sets_unlimited()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->withGracePeriod(null);

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * T1.7拡張: enableRecovery() で recoverable が true、猶予期間が無制限になる
     *
     * @test
     */
    public function enableRecovery_sets_recoverable_with_unlimited_grace()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->enableRecovery();

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * dispatchVia() returns self for method chaining
     *
     * @test
     */
    public function dispatchVia_returns_self_for_method_chaining()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $result = $event->dispatchVia('local');

        $this->assertSame($event, $result);
    }

    /**
     * getDispatcherType() returns null by default
     *
     * @test
     */
    public function getDispatcherType_returns_null_by_default()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $this->assertNull($event->getDispatcherType());
    }

    /**
     * dispatchVia() sets dispatcher type
     *
     * @test
     */
    public function dispatchVia_sets_dispatcher_type()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->dispatchVia('stepfunctions');

        $this->assertEquals('stepfunctions', $event->getDispatcherType());
    }
}
