<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * CompositeDispatcher の統合テスト
 */
class CompositeDispatcherIntegrationTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $eventMutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->eventMutex = new FakeEventMutex();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox T5.1 mixed_dispatchers_in_same_schedule
     */
    public function testMixedDispatchersInSameSchedule(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $dueAt = new DateTimeImmutable('2024-01-15 12:00:00');

        $localResult = FakeStartedDispatchResult::create('local-event', 'echo local', 'local');
        $sfnResult = FakeStartedDispatchResult::create('sfn-event', 'echo sfn', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $composite = new CompositeDispatcher(
            ['local' => $localDispatcher, 'stepfunctions' => $sfnDispatcher],
            'local'
        );

        // local イベント（dispatchVia 未指定 → デフォルト 'local'）
        $localEvent = new ClockAwareEvent($this->eventMutex, 'echo local', $clock);
        $localEvent->cron('* * * * *');

        // stepfunctions イベント（dispatchVia で指定）
        $sfnEvent = new ClockAwareEvent($this->eventMutex, 'echo sfn', $clock);
        $sfnEvent->cron('* * * * *');
        $sfnEvent->dispatchVia('stepfunctions');

        // local イベントを dispatch
        $result1 = $composite->dispatchEvent($localEvent, $this->container, $dueAt);
        $this->assertSame('local', $result1->getDispatcherType());
        $this->assertSame(1, $localDispatcher->getDispatchCount());
        $this->assertSame(0, $sfnDispatcher->getDispatchCount());

        // sfn イベントを dispatch
        $result2 = $composite->dispatchEvent($sfnEvent, $this->container, $dueAt);
        $this->assertSame('stepfunctions', $result2->getDispatcherType());
        $this->assertSame(1, $localDispatcher->getDispatchCount());
        $this->assertSame(1, $sfnDispatcher->getDispatchCount());
    }
}
