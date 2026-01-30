<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class ClockAwareScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup Container for Schedule to work
        $container = new Container();
        Container::setInstance($container);

        // Bind EventMutex and SchedulingMutex
        $container->bind(EventMutex::class, function () {
            return $this->createMock(CacheEventMutex::class);
        });

        $container->bind(SchedulingMutex::class, function () {
            return $this->createMock(CacheSchedulingMutex::class);
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }
    /**
     * T1.8: command() は ClockAwareEvent を返す
     *
     * @test
     */
    public function it_returns_clock_aware_event_from_command()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->command('php artisan test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * T1.9: exec() は ClockAwareEvent を返す
     *
     * @test
     */
    public function it_returns_clock_aware_event_from_exec()
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('ls -la');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * T1.10: 全イベントが同じ Clock を持つ
     *
     * @test
     */
    public function it_injects_same_clock_to_all_events()
    {
        $fixedTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $clock = new FixedClock($fixedTime);
        $schedule = new ClockAwareSchedule($clock);

        $event1 = $schedule->command('php artisan test1');
        $event2 = $schedule->command('php artisan test2');
        $event3 = $schedule->exec('ls -la');

        // すべてのイベントが同じClockから同じ時刻を取得する
        $this->assertEquals($fixedTime, $event1->getCurrentTime());
        $this->assertEquals($fixedTime, $event2->getCurrentTime());
        $this->assertEquals($fixedTime, $event3->getCurrentTime());
    }
}
