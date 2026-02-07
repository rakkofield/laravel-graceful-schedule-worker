<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;

/**
 * ClockAwareSchedule の Laravel 互換性テスト
 *
 * Laravel Schedule の標準機能が ClockAwareSchedule でも正しく動作することを保証する。
 * コード変更は不要。既存動作の文書化・保証を目的とする。
 *
 * - call() は CallbackEvent を返す（ClockAwareEvent ではない）
 * - exec() で withGracePeriod() / enableRecovery() / dispatchVia() のチェーンが可能
 * - チェーン順序に依存しない
 * - timezone がイベントに伝播する
 */
class ClockAwareScheduleCompatibilityTest extends TestCase
{
    /** @var FixedClock */
    private $clock;

    /** @var ClockAwareSchedule */
    private $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);

        $container->bind(EventMutex::class, function () {
            return new FakeEventMutex();
        });

        $container->bind(SchedulingMutex::class, function () {
            return new FakeSchedulingMutex();
        });

        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $this->schedule = new ClockAwareSchedule($this->clock);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox T5.1 call() returns CallbackEvent (not ClockAwareEvent)
     */
    public function testCallReturnsCallbackEvent(): void
    {
        $event = $this->schedule->call(function () {
            return true;
        });

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox T5.2 exec()->daily()->withGracePeriod(30) chain
     */
    public function testExecDailyWithGracePeriodChain(): void
    {
        $event = $this->schedule->exec('echo test')
            ->daily()
            ->withGracePeriod(30);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertTrue($event->isRecoverable());
        $this->assertNotNull($event->getGracePeriod());
        $this->assertSame('0 0 * * *', $event->expression);
    }

    /**
     * @testdox T5.3 exec()->withGracePeriod(30)->daily() order-independent chain
     */
    public function testExecWithGracePeriodDailyChain(): void
    {
        $event = $this->schedule->exec('echo test')
            ->withGracePeriod(30)
            ->daily();

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertTrue($event->isRecoverable());
        $this->assertNotNull($event->getGracePeriod());
        $this->assertSame('0 0 * * *', $event->expression);
    }

    /**
     * @testdox T5.4 exec()->hourly()->dispatchVia()->enableRecovery() triple chain
     */
    public function testTripleMethodChain(): void
    {
        $event = $this->schedule->exec('echo test')
            ->hourly()
            ->dispatchVia('stepfunctions')
            ->enableRecovery();

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertSame('stepfunctions', $event->getDispatcherType());
        $this->assertTrue($event->isRecoverable());
    }

    /**
     * @testdox T5.5 Constructor timezone propagates to events
     */
    public function testTimezonePropagatesToEvents(): void
    {
        $schedule = new ClockAwareSchedule($this->clock, 'Asia/Tokyo');
        $event = $schedule->exec('echo test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }
}
