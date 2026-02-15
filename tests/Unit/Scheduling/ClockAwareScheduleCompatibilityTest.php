<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;

/**
 * Laravel compatibility tests for ClockAwareSchedule
 *
 * Ensures standard Laravel Schedule features work correctly with ClockAwareSchedule.
 * No code changes required. Purpose is to document and guarantee existing behavior.
 *
 * - call() returns CallbackEvent (not ClockAwareEvent)
 * - exec() can chain withGracePeriod() / enableRecovery() / dispatchVia()
 * - Chain order independent
 * - timezone propagates to events
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
     * @testdox CSC.1 call() returns CallbackEvent (not ClockAwareEvent)
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
     * @testdox CSC.2 exec()->daily()->withGracePeriod(30) chain
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
     * @testdox CSC.3 exec()->withGracePeriod(30)->daily() order-independent chain
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
     * @testdox CSC.4 exec()->hourly()->dispatchVia()->enableRecovery() triple chain
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
     * @testdox CSC.5 Constructor timezone propagates to events
     */
    public function testTimezonePropagatesToEvents(): void
    {
        $schedule = new ClockAwareSchedule($this->clock, 'local', 'Asia/Tokyo');
        $event = $schedule->exec('echo test');

        $this->assertInstanceOf(ClockAwareEvent::class, $event);
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    /**
     * @testdox CSC.6 dueEvents() returns only events due at clock time
     */
    public function testDueEventsReturnsOnlyEventsDueAtClockTime(): void
    {
        $app = new FakeApplication();

        // clock = 12:00 -> everyMinute is due, dailyAt('03:00') is not due
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:00:00'));

        $this->schedule->exec('echo every-minute')->everyMinute();
        $this->schedule->exec('echo daily-three')->dailyAt('03:00');

        $dueEvents = $this->schedule->dueEvents($app)->all();

        $this->assertCount(1, $dueEvents);
        $this->assertStringContainsString('every-minute', $dueEvents[0]->command);
    }

    /**
     * @testdox CSC.7 evaluateAt() freezes time for dueEvents() evaluation
     */
    public function testEvaluateAtFreezesDueEventsEvaluation(): void
    {
        $app = new FakeApplication();

        // Clock's actual time is 12:00, but frozen to 03:00 via evaluateAt
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:00:00'));

        $this->schedule->exec('echo every-minute')->everyMinute();
        $this->schedule->exec('echo daily-three')->dailyAt('03:00');

        $frozenTime = new DateTimeImmutable('2024-01-15 03:00:00');
        $dueEvents = [];

        $this->schedule->evaluateAt($frozenTime, function () use ($app, &$dueEvents) {
            $dueEvents = $this->schedule->dueEvents($app)->all();
        });

        // Frozen to 03:00 -> both everyMinute and dailyAt('03:00') are due
        $this->assertCount(2, $dueEvents);
    }

    /**
     * @testdox CSC.8 job() returns CallbackEvent (not ClockAwareEvent)
     */
    public function testJobReturnsCallbackEvent(): void
    {
        // job() uses call() internally, so it returns CallbackEvent
        $event = $this->schedule->job(new class {
            public function handle(): void
            {
            }
        });

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertNotInstanceOf(ClockAwareEvent::class, $event);
    }

    /**
     * @testdox CSC.9 events() returns mixed ClockAwareEvent and CallbackEvent
     */
    public function testEventsReturnsMixedTypes(): void
    {
        $this->schedule->exec('echo test');
        $this->schedule->command('echo test2');
        $this->schedule->call(function () {
            return true;
        });

        $events = $this->schedule->events();

        $this->assertCount(3, $events);
        $this->assertInstanceOf(ClockAwareEvent::class, $events[0]);
        $this->assertInstanceOf(ClockAwareEvent::class, $events[1]);
        $this->assertInstanceOf(CallbackEvent::class, $events[2]);
    }

    /**
     * @testdox CSC.10 dueEvents() changes result when clock advances
     */
    public function testDueEventsChangesWhenClockAdvances(): void
    {
        $app = new FakeApplication();

        $this->schedule->exec('echo daily-noon')->dailyAt('12:00');

        // 12:00 → due
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:00:00'));
        $this->assertCount(1, $this->schedule->dueEvents($app)->all());

        // 12:01 → not due
        $this->clock->setTime(new DateTimeImmutable('2024-01-15 12:01:00'));
        $this->assertCount(0, $this->schedule->dueEvents($app)->all());
    }
}
