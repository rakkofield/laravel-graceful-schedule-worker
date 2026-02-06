<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeFailedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpySchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;

/**
 * Orchestrator のリカバリフローと TrackingDispatcher の統合テスト
 */
class OrchestratorRecoveryIntegrationTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $eventMutex;

    /** @var FakeSchedulingMutex */
    private $schedulingMutex;

    /** @var FakeLockProvider */
    private $lockProvider;

    /** @var FakeCacheStore */
    private $cache;

    /** @var SpyLogger */
    private $logger;

    /** @var FakeDispatcher */
    private $innerDispatcher;

    /** @var SpySchedule */
    private $schedule;

    /** @var FakeApplication */
    private $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        Container::setInstance($this->container);

        $this->eventMutex = new FakeEventMutex();
        $this->schedulingMutex = new FakeSchedulingMutex();
        $this->lockProvider = new FakeLockProvider();
        $this->cache = new FakeCacheStore($this->lockProvider);
        $this->logger = new SpyLogger();

        $defaultResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $this->innerDispatcher = new FakeDispatcher($defaultResult);
        $this->schedule = new SpySchedule($this->eventMutex, $this->schedulingMutex);
        $this->app = new FakeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param string $command
     * @param FixedClock $clock
     * @return ClockAwareEvent
     */
    private function createEvent(string $command, FixedClock $clock): ClockAwareEvent
    {
        return new ClockAwareEvent($this->eventMutex, $command, $clock);
    }

    /**
     * @return CacheExecutionTracker
     */
    private function createTracker(): CacheExecutionTracker
    {
        return new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
    }

    /**
     * @param TrackingDispatcher $trackingDispatcher
     * @param ClockInterface $clock
     * @param CacheExecutionTracker $tracker
     * @return DefaultScheduleOrchestrator
     */
    private function createOrchestrator(
        TrackingDispatcher $trackingDispatcher,
        ClockInterface $clock,
        CacheExecutionTracker $tracker
    ): DefaultScheduleOrchestrator {
        return new DefaultScheduleOrchestrator($trackingDispatcher, $clock, $tracker, $this->logger, 0);
    }

    /**
     * @param int $maxCalls
     * @return callable
     */
    private function createShouldContinue(int $maxCalls): callable
    {
        $callCount = 0;
        return function () use (&$callCount, $maxCalls) {
            return $callCount++ < $maxCalls;
        };
    }

    /**
     * @testdox T9.1 Recovery acquires lock and marks executed
     */
    public function testRecoveryAcquiresLockAndMarksExecuted(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();

        $event = $this->createEvent('echo test', $clock);
        $event->cron('0 * * * *');
        $event->withGracePeriod(120);

        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $result = FakeStartedDispatchResult::create($event->mutexName(), 'echo test', 'fake');
        $this->innerDispatcher->setResult($result);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = $this->createOrchestrator($trackingDispatcher, $clock, $tracker);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());

        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
        $lastTimestamp = (int) $this->cache->get($key);
        $recovered = Carbon::createFromTimestamp($lastTimestamp);
        $this->assertSame(11, $recovered->hour);
        $this->assertSame(0, $recovered->minute);

        $this->assertTrue($this->logger->hasLogContaining('info', 'Recovering missed event'));
    }

    /**
     * @testdox T9.2 Recovery skipped when lock already held
     */
    public function testRecoverySkippedWhenLockAlreadyHeld(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();

        $event = $this->createEvent('echo test-lock', $clock);
        $event->cron('0 * * * *');
        $event->withGracePeriod(120);

        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        // 事前にロックを取得（他のワーカーが先に取得した状況）
        $missedDue = Carbon::parse('2024-01-15 11:00:00');
        $tracker->acquireLock($event, $missedDue);

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = $this->createOrchestrator($trackingDispatcher, $clock, $tracker);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(0, $this->innerDispatcher->getDispatchCount());
        $this->assertTrue($this->logger->hasLogContaining('debug', 'Lock not acquired'));
    }

    /**
     * @testdox T9.3 Multiple events with different grace periods
     */
    public function testMultipleEventsWithDifferentGracePeriods(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 14:05:00'));
        $tracker = $this->createTracker();

        // Event 1: grace period 内（5時間 → 10:00+5h = 15:00 > now=14:05）
        $event1 = $this->createEvent('echo event1', $clock);
        $event1->cron('0 * * * *');
        $event1->withGracePeriod(300);
        $tracker->markExecuted($event1, Carbon::parse('2024-01-15 10:00:00'));

        // Event 2: grace period 超過（2時間 → 10:00+2h = 12:00 < now=14:05）
        $event2 = $this->createEvent('echo event2', $clock);
        $event2->cron('0 * * * *');
        $event2->withGracePeriod(120);
        $tracker->markExecuted($event2, Carbon::parse('2024-01-15 10:00:00'));

        // Event 3: recovery 無効
        $event3 = $this->createEvent('echo event3', $clock);
        $event3->cron('0 * * * *');

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event1);
        $this->schedule->addEvent($event2);
        $this->schedule->addEvent($event3);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = $this->createOrchestrator($trackingDispatcher, $clock, $tracker);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());
        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertSame($event1, $dispatched[0]['event']);
    }

    /**
     * @testdox T9.4 Recovery then normal dispatch in same run
     */
    public function testRecoveryThenNormalDispatchInSameRun(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = $this->createTracker();

        // リカバリ対象のイベント
        $recoveryEvent = $this->createEvent('echo recovery', $clock);
        $recoveryEvent->cron('0 * * * *');
        $recoveryEvent->withGracePeriod(120);
        $tracker->markExecuted($recoveryEvent, Carbon::parse('2024-01-15 10:00:00'));

        // 通常の due イベント
        $dueEvent = $this->createEvent('echo due', $clock);
        $dueEvent->cron('0 * * * *');

        $this->schedule->setDueEvents([$dueEvent]);
        $this->schedule->addEvent($recoveryEvent);
        $this->schedule->addEvent($dueEvent);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = $this->createOrchestrator($trackingDispatcher, $clock, $tracker);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(2));

        // リカバリ1回 + 通常dispatch1回 = 2回
        $this->assertSame(2, $this->innerDispatcher->getDispatchCount());
    }

    /**
     * @testdox T9.5 Recovery with failed dispatch logs error
     */
    public function testRecoveryWithFailedDispatchLogsError(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();

        $event = $this->createEvent('echo test-fail', $clock);
        $event->cron('0 * * * *');
        $event->withGracePeriod(120);
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $failedResult = FakeFailedDispatchResult::create($event->mutexName(), 'echo test-fail', 'connection lost');
        $this->innerDispatcher->setResult($failedResult);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = $this->createOrchestrator($trackingDispatcher, $clock, $tracker);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        $this->assertSame(1, $this->innerDispatcher->getDispatchCount());
        $this->assertTrue($this->logger->hasLogContaining('error', 'Failed to dispatch event'));

        // markExecuted されていない（初期設定の10:00のまま）
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $lastTimestamp = (int) $this->cache->get($key);
        $lastExecuted = Carbon::createFromTimestamp($lastTimestamp);
        $this->assertSame(10, $lastExecuted->hour);
    }

    /**
     * @testdox T9.6 Recovery dispatch passes missed due as timestamp
     */
    public function testRecoveryDispatchPassesMissedDueAsTimestamp(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 11:05:00'));
        $tracker = $this->createTracker();

        $event = $this->createEvent('echo test-due', $clock);
        $event->cron('0 * * * *');
        $event->withGracePeriod(120);
        $tracker->markExecuted($event, Carbon::parse('2024-01-15 10:00:00'));

        $this->schedule->setDueEvents([]);
        $this->schedule->addEvent($event);

        $trackingDispatcher = new TrackingDispatcher($this->innerDispatcher, $tracker, $this->logger);
        $orchestrator = $this->createOrchestrator($trackingDispatcher, $clock, $tracker);

        $orchestrator->run($this->schedule, $this->app, $this->createShouldContinue(1));

        $dispatched = $this->innerDispatcher->getDispatched();
        $this->assertCount(1, $dispatched);
        $dueAt = $dispatched[0]['dueAt'];
        // dueAt は 11:00（missed due）であり、11:05（現在時刻）ではない
        $this->assertSame(11, (int) Carbon::instance($dueAt)->format('H'));
        $this->assertSame(0, (int) Carbon::instance($dueAt)->format('i'));
    }
}
