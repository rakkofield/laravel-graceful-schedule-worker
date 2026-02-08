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
     * @testdox CEC.1 everyMinute() sets correct cron expression
     */
    public function testEveryMinuteSetsCorrectCron(): void
    {
        $event = $this->createEvent();
        $event->everyMinute();

        $this->assertSame('* * * * *', $event->expression);
    }

    /**
     * @testdox CEC.2 hourly() sets correct cron expression
     */
    public function testHourlySetsCorrectCron(): void
    {
        $event = $this->createEvent();
        $event->hourly();

        $this->assertSame('0 * * * *', $event->expression);
    }

    /**
     * @testdox CEC.3 daily() and dailyAt() set correct cron expressions
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
     * @testdox CEC.4 when(true) passes filtersPass
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
     * @testdox CEC.5 when(false) fails filtersPass
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
     * @testdox CEC.6 skip(true) fails filtersPass
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
     * @testdox CEC.7 between() adds filter
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
     * @testdox CEC.8 withoutOverlapping adds skip filter based on EventMutex
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
     * @testdox CEC.9 weekdays() modifies cron expression
     */
    public function testWeekdaysModifiesCron(): void
    {
        $event = $this->createEvent();
        $event->daily()->weekdays();

        $this->assertSame('0 0 * * 1-5', $event->expression);
    }

    /**
     * @testdox CEC.10 expressionPasses uses injected clock
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
     * @testdox CEC.11 expressionPasses with different clock time
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
     * @testdox CEC.12 expressionPasses respects timezone
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
     * @testdox CEC.13 between uses injected clock
     */
    public function testBetweenUsesInjectedClock(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = $this->createEvent();
        $event->between('09:00', '17:00');

        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * @testdox CEC.14 between rejects outside range
     */
    public function testBetweenRejectsOutsideRange(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 20:00:00'));
        $event = $this->createEvent();
        $event->between('09:00', '17:00');

        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @testdox CEC.15 between evaluates lazily not at definition time
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
     * @testdox CEC.16 unlessBetween uses injected clock
     */
    public function testUnlessBetweenUsesInjectedClock(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 20:00:00'));
        $event = $this->createEvent();
        $event->unlessBetween('09:00', '17:00');

        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * @testdox CEC.17 unlessBetween rejects inside range
     */
    public function testUnlessBetweenRejectsInsideRange(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = $this->createEvent();
        $event->unlessBetween('09:00', '17:00');

        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @testdox CEC.18 between handles midnight crossing
     */
    public function testBetweenHandlesMidnightCrossing(): void
    {
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 23:30:00'));
        $event = $this->createEvent();
        $event->between('22:00', '06:00');

        $this->assertTrue($event->filtersPass($this->app));
    }

    /**
     * @testdox CEC.19 environments affects isDue
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
     * @testdox CEC.20 evenInMaintenanceMode affects isDue
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
     * @testdox CEC.21 maintenance mode default blocks isDue
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
     * @testdox CEC.22 description is stored on event
     */
    public function testDescriptionIsStoredOnEvent(): void
    {
        $event = $this->createEvent();
        $event->description('my-task');

        $this->assertSame('my-task', $event->description);
    }

    /**
     * @testdox CEC.23 typical Kernel.php chaining pattern works
     */
    public function testTypicalKernelChainPatternWorks(): void
    {
        $event = $this->createEvent('php artisan hello');
        $result = $event->everyMinute()
            ->runInBackground()
            ->withGracePeriod(30)
            ->appendOutputTo('/tmp/test.log')
            ->before(function () {
                // no-op
            })
            ->onSuccess(function () {
                // no-op
            })
            ->onFailure(function () {
                // no-op
            })
            ->after(function () {
                // no-op
            });

        $this->assertSame($event, $result);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->isRecoverable());
        $this->assertNotNull($event->getGracePeriod());
        $this->assertTrue($event->runInBackground);
    }

    /**
     * @testdox CEC.24 onOneServer chaining works
     */
    public function testOnOneServerChaining(): void
    {
        $event = $this->createEvent();
        $result = $event->everyMinute()
            ->onOneServer()
            ->withGracePeriod(30);

        $this->assertSame($event, $result);
        $this->assertTrue($event->isRecoverable());
    }

    /**
     * @testdox CEC.25 withGracePeriod(0) sets recoverable without grace period
     */
    public function testWithGracePeriodZeroSetsRecoverableWithoutGracePeriod(): void
    {
        $event = $this->createEvent();
        $event->withGracePeriod(0);

        $this->assertTrue($event->isRecoverable());
        $this->assertNull($event->getGracePeriod());
    }

    /**
     * @testdox CEC.26 everyFiveMinutes sets correct cron expression
     */
    public function testEveryFiveMinutesSetsCorrectCron(): void
    {
        $event = $this->createEvent();
        $event->everyFiveMinutes();

        $this->assertSame('*/5 * * * *', $event->expression);
    }

    /**
     * @testdox CEC.27 dispatchVia chained with schedule methods
     */
    public function testDispatchViaChainedWithScheduleMethods(): void
    {
        $event = $this->createEvent();
        $result = $event->hourly()
            ->dispatchVia('stepfunctions')
            ->enableRecovery()
            ->runInBackground();

        $this->assertSame($event, $result);
        $this->assertSame('stepfunctions', $event->getDispatcherType());
        $this->assertTrue($event->isRecoverable());
        $this->assertTrue($event->runInBackground);
    }

    /**
     * @testdox CEC.28 getSummaryForDisplay returns command summary
     */
    public function testGetSummaryForDisplayReturnsCommandSummary(): void
    {
        $event = $this->createEvent('php artisan report:generate');
        $summary = $event->getSummaryForDisplay();

        $this->assertStringContainsString('report:generate', $summary);
    }

    /**
     * @testdox CEC.29 mutexName is consistent for same command
     */
    public function testMutexNameConsistentForSameCommand(): void
    {
        $event1 = $this->createEvent('php artisan test');
        $event2 = $this->createEvent('php artisan test');

        $this->assertSame($event1->mutexName(), $event2->mutexName());
    }
}
