<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;

class ClockAwareEventTest extends TestCase
{
    /**
     * @var FakeEventMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutex = new FakeEventMutex();
    }

    /**
     * @testdox T1.4
     */
    public function testCanInjectClock(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox T1.5
     */
    public function testCanSetGracePeriod(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $result = $event->withGracePeriod(30);

        $this->assertSame($event, $result, 'withGracePeriod should return self for method chaining');
    }

    /**
     * @testdox T1.6
     */
    public function testCanEnableRecovery(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $result = $event->enableRecovery();

        $this->assertSame($event, $result, 'enableRecovery should return self for method chaining');
    }

    /**
     * @testdox T1.8
     */
    public function testRecoverableIsFalseByDefault(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $this->assertFalse($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox T1.5.1
     */
    public function testWithGracePeriodSetsRecoverableToTrue(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->withGracePeriod(30);

        $this->assertTrue($event->isRecoverable());
    }

    /**
     * @testdox T1.5.2
     */
    public function testWithGracePeriodSetsCorrectInterval(): void
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
     * @testdox T1.5.3
     */
    public function testWithGracePeriodWithNullSetsUnlimited(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->withGracePeriod(null);

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox T1.6.1
     */
    public function testEnableRecoverySetsRecoverableWithUnlimitedGrace(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->enableRecovery();

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox T1.9
     */
    public function testDispatchViaReturnsSelfForMethodChaining(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $result = $event->dispatchVia('local');

        $this->assertSame($event, $result);
    }

    /**
     * @testdox T1.10
     */
    public function testGetDispatcherTypeReturnsNullByDefault(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $this->assertNull($event->getDispatcherType());
    }

    /**
     * @testdox T1.11
     */
    public function testDispatchViaSetsDispatcherType(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);

        $event->dispatchVia('stepfunctions');

        $this->assertEquals('stepfunctions', $event->getDispatcherType());
    }

    /**
     * @testdox T1.12.1 buildProcessCommand includes schedule:finish
     */
    public function testBuildProcessCommandIncludesScheduleFinish(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;

        $command = $event->buildProcessCommand();

        $this->assertStringContainsString('schedule:finish', $command);
    }

    /**
     * @testdox T1.12.2 buildProcessCommand does not end with &
     */
    public function testBuildProcessCommandDoesNotEndWithAmpersand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;

        $command = $event->buildProcessCommand();

        $this->assertNotRegExp('/\s+&\s*$/', $command);
    }
}
