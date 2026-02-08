<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FreezableClock;

class ClockAwareScheduleTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox CS.1 command() が ClockAwareEvent を返す
     */
    public function testReturnsClockAwareEventFromCommand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->command('php artisan test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.2 exec() が ClockAwareEvent を返す
     */
    public function testReturnsClockAwareEventFromExec(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('ls -la');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.3 複数のメソッドから ClockAwareEvent を生成できる
     */
    public function testCreatesClockAwareEventsFromMultipleMethods(): void
    {
        $fixedTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $clock = new FixedClock($fixedTime);
        $schedule = new ClockAwareSchedule($clock);

        $event1 = $schedule->command('php artisan test1');
        $event2 = $schedule->command('php artisan test2');
        $event3 = $schedule->exec('ls -la');

        $this->assertInstanceOf(ClockAwareEvent::class, $event1);
        $this->assertInstanceOf(ClockAwareEvent::class, $event2);
        $this->assertInstanceOf(ClockAwareEvent::class, $event3);
    }

    /**
     * @testdox CS.4 exec() がパラメータを正しく処理する
     */
    public function testExecHandlesParametersCorrectly(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('command', ['--foo' => 'bar', '--baz']);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.5 command() がパラメータを正しく処理する
     */
    public function testCommandHandlesParametersCorrectly(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->command('php artisan test', ['--option' => 'value']);

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.6 evaluateAt がイベントの Clock を凍結する
     */
    public function testEvaluateAtFreezesEventClock(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $clock = new FixedClock($innerTime);
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('echo test');

        $schedule->evaluateAt($frozenTime, function () use ($event, $frozenTime) {
            // ClockAwareEvent の clock（= eventClock）が frozen time を返すことを検証
            // expressionPasses は protected なので、clock->now() を間接的に確認
            $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
            $reflection->setAccessible(true);
            $eventClock = $reflection->getValue($event);

            $this->assertEquals($frozenTime, $eventClock->now());
        });
    }

    /**
     * @testdox CS.7 evaluateAt 完了後に凍結が解除される
     */
    public function testEvaluateAtUnfreezesAfterCallback(): void
    {
        $innerTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $frozenTime = new DateTimeImmutable('2024-01-15 10:30:00');
        $clock = new FixedClock($innerTime);
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('echo test');

        $schedule->evaluateAt($frozenTime, function () {
            // no-op
        });

        // evaluateAt 完了後はイベントが live clock に戻る
        $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
        $reflection->setAccessible(true);
        $eventClock = $reflection->getValue($event);

        $this->assertEquals($innerTime, $eventClock->now());
    }

    /**
     * @testdox CS.8 exec() が FreezableClock をイベントに渡す
     */
    public function testExecPassesFreezableClockToEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = $schedule->exec('echo test');

        $reflection = new \ReflectionProperty(ClockAwareEvent::class, 'clock');
        $reflection->setAccessible(true);
        $eventClock = $reflection->getValue($event);

        $this->assertInstanceOf(FreezableClock::class, $eventClock);
    }

    /**
     * @testdox CS.9 withNativeEvents 内の exec() が Event（ClockAwareEvent でない）を返す
     */
    public function testWithNativeEventsExecReturnsNativeEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = null;
        $schedule->withNativeEvents(function () use ($schedule, &$event) {
            $event = $schedule->exec('ls -la');
        });

        $this->assertInstanceOf(Event::class, $event);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.10 withNativeEvents 内の command() が Event を返す
     */
    public function testWithNativeEventsCommandReturnsNativeEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $event = null;
        $schedule->withNativeEvents(function () use ($schedule, &$event) {
            $event = $schedule->command('php artisan test');
        });

        $this->assertInstanceOf(Event::class, $event);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.11 withNativeEvents 完了後は ClockAwareEvent に戻る
     */
    public function testAfterWithNativeEventsReturnsClockAwareEvent(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('ls -la');
        });

        $event = $schedule->exec('echo test');
        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.12 withNativeEvents 内で例外が発生してもモードが復元される
     */
    public function testWithNativeEventsRestoresModeOnException(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        try {
            $schedule->withNativeEvents(function () {
                throw new \RuntimeException('test exception');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $event = $schedule->exec('echo test');
        $this->assertInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CS.13 withNativeEvents 内と外のイベントが同一 events 配列に共存する
     */
    public function testNativeAndClockAwareEventsCoexistInEventsArray(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $schedule->withNativeEvents(function () use ($schedule) {
            $schedule->exec('native-command');
        });
        $schedule->exec('clock-aware-command');

        $events = $schedule->events();
        $this->assertCount(2, $events);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $events[0]);
        $this->assertInstanceOf(ClockAwareEvent::class, $events[1]);
    }
}
