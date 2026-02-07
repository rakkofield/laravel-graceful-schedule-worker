<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;

/**
 * ClockAwareEvent の Laravel Event 互換性テスト
 *
 * Laravel Event の標準機能（cron スケジューリング、フィルタ、mutex）が
 * ClockAwareEvent でも正しく動作することを保証する。
 * コード変更は不要。既存動作の文書化・保証を目的とする。
 *
 * - cron/everyMinute/hourly/daily 等のスケジューリングメソッド
 * - when()/skip() フィルタの filtersPass() 反映
 * - between() フィルタ追加
 * - withoutOverlapping() の EventMutex 連携
 * - weekdays() 等の曜日制約
 */
class ClockAwareEventCompatibilityTest extends TestCase
{
    /** @var FakeEventMutex */
    private $mutex;

    /** @var FixedClock */
    private $clock;

    /** @var FakeApplication */
    private $app;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);

        $this->mutex = new FakeEventMutex();
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $this->app = new FakeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param string $command
     * @return ClockAwareEvent
     */
    private function createEvent(string $command = 'echo test'): ClockAwareEvent
    {
        return new ClockAwareEvent($this->mutex, $command, $this->clock);
    }

    /**
     * @testdox T6.1 everyMinute() sets correct cron expression
     */
    public function testEveryMinuteSetsCorrectCron(): void
    {
        $event = $this->createEvent();
        $event->everyMinute();

        $this->assertSame('* * * * *', $event->expression);
    }

    /**
     * @testdox T6.2 hourly() sets correct cron expression
     */
    public function testHourlySetsCorrectCron(): void
    {
        $event = $this->createEvent();
        $event->hourly();

        $this->assertSame('0 * * * *', $event->expression);
    }

    /**
     * @testdox T6.3 daily() and dailyAt() set correct cron expressions
     */
    public function testDailySetsCorrectCron(): void
    {
        $event1 = $this->createEvent('echo daily');
        $event1->daily();
        $this->assertSame('0 0 * * *', $event1->expression);

        $event2 = $this->createEvent('echo dailyAt');
        $event2->dailyAt('13:30');
        $this->assertSame('30 13 * * *', $event2->expression);
    }

    /**
     * @testdox T6.4 when(true) passes filtersPass
     */
    public function testWhenTruePassesFiltersPass(): void
    {
        $event = $this->createEvent();
        $event->when(function () {
            return true;
        });

        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.5 when(false) fails filtersPass
     */
    public function testWhenFalseFailsFiltersPass(): void
    {
        $event = $this->createEvent();
        $event->when(function () {
            return false;
        });

        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.6 skip(true) fails filtersPass
     */
    public function testSkipTrueFailsFiltersPass(): void
    {
        $event = $this->createEvent();
        $event->skip(function () {
            return true;
        });

        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.7 between() adds filter
     */
    public function testBetweenAddsFilter(): void
    {
        $event = $this->createEvent();
        $result = $event->between('08:00', '17:00');

        $this->assertSame($event, $result);
        // between() adds a filter; verify filtersPass returns a boolean
        $this->assertIsBool($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.8 withoutOverlapping adds skip filter based on EventMutex
     */
    public function testWithoutOverlappingAddsSkipFilter(): void
    {
        $event = $this->createEvent();
        $event->withoutOverlapping();

        // mutex not locked → filtersPass should pass
        $this->assertTrue($event->filtersPass($this->app));

        // Now lock the mutex (simulate a running event)
        $this->mutex->create($event);

        // 同一コマンド ('echo test') → 同一 mutexName → ロック済み mutex にヒットする
        $event2 = $this->createEvent();
        $event2->withoutOverlapping();

        // mutex locked → filtersPass should fail
        $this->assertFalse($event2->filtersPass($this->app));
    }

    /**
     * @testdox T6.9 weekdays() modifies cron expression
     */
    public function testWeekdaysModifiesCron(): void
    {
        $event = $this->createEvent();
        $event->daily()->weekdays();

        $this->assertSame('0 0 * * 1-5', $event->expression);
    }

    /**
     * @testdox T6.10 expressionPasses uses injected clock
     */
    public function testExpressionPassesUsesInjectedClock(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:00:00'));
        $event = $this->createEvent();
        $event->dailyAt('12:00');

        $reflection = new \ReflectionMethod($event, 'expressionPasses');
        $reflection->setAccessible(true);

        $this->assertTrue($reflection->invoke($event));
    }

    /**
     * @testdox T6.11 expressionPasses with different clock time
     */
    public function testExpressionPassesWithDifferentClockTime(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 13:00:00'));
        $event = $this->createEvent();
        $event->dailyAt('12:00');

        $reflection = new \ReflectionMethod($event, 'expressionPasses');
        $reflection->setAccessible(true);

        $this->assertFalse($reflection->invoke($event));
    }

    /**
     * @testdox T6.12 expressionPasses respects timezone
     */
    public function testExpressionPassesRespectsTimezone(): void
    {
        // UTC 03:00 = Asia/Tokyo 12:00 (UTC+9)
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 03:00:00', new DateTimeZone('UTC')));
        $event = $this->createEvent();
        $event->dailyAt('12:00')->timezone('Asia/Tokyo');

        $reflection = new \ReflectionMethod($event, 'expressionPasses');
        $reflection->setAccessible(true);

        $this->assertTrue($reflection->invoke($event));
    }

    /**
     * @testdox T6.13 between uses injected clock
     */
    public function testBetweenUsesInjectedClock(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = $this->createEvent();
        $event->between('09:00', '17:00');

        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.14 between rejects outside range
     */
    public function testBetweenRejectsOutsideRange(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 20:00:00'));
        $event = $this->createEvent();
        $event->between('09:00', '17:00');

        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.15 between evaluates lazily not at definition time
     */
    public function testBetweenEvaluatesLazilyNotAtDefinitionTime(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = $this->createEvent();
        $event->between('09:00', '17:00');

        // 定義時は範囲内
        $this->assertTrue($event->filtersPass($this->app));

        // clock を範囲外に変更
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 20:00:00'));

        // 遅延評価なので新しい時刻で再評価される
        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.16 unlessBetween uses injected clock
     */
    public function testUnlessBetweenUsesInjectedClock(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 20:00:00'));
        $event = $this->createEvent();
        $event->unlessBetween('09:00', '17:00');

        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.17 unlessBetween rejects inside range
     */
    public function testUnlessBetweenRejectsInsideRange(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = $this->createEvent();
        $event->unlessBetween('09:00', '17:00');

        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.18 between handles midnight crossing
     */
    public function testBetweenHandlesMidnightCrossing(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 23:30:00'));
        $event = $this->createEvent();
        $event->between('22:00', '06:00');

        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * @testdox T6.19 environments affects isDue
     */
    public function testEnvironmentsAffectsIsDue(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:00:00'));
        $event = $this->createEvent();
        $event->everyMinute()->environments(['production']);

        $this->app->setEnvironment('testing');

        $this->assertFalse($event->isDue($this->app));
    }

    /**
     * @testdox T6.20 evenInMaintenanceMode affects isDue
     */
    public function testEvenInMaintenanceModeAffectsIsDue(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:00:00'));
        $event = $this->createEvent();
        $event->everyMinute()->evenInMaintenanceMode();

        $this->app->setIsDownForMaintenance(true);

        $this->assertTrue($event->isDue($this->app));
    }

    /**
     * @testdox T6.21 maintenance mode default blocks isDue
     */
    public function testMaintenanceModeDefaultBlocksIsDue(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:00:00'));
        $event = $this->createEvent();
        $event->everyMinute();

        $this->app->setIsDownForMaintenance(true);

        $this->assertFalse($event->isDue($this->app));
    }

    /**
     * @testdox T6.22 description is stored on event
     */
    public function testDescriptionIsStoredOnEvent(): void
    {
        $event = $this->createEvent();
        $event->description('my-task');

        $this->assertSame('my-task', $event->description);
    }
}
