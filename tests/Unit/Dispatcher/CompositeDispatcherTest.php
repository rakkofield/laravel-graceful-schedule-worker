<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

class CompositeDispatcherTest extends TestCase
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @var FakeEventMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    private function createClockAwareEvent(string $command, ?string $dispatcherType = null): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, $command, $clock);
        if ($dispatcherType !== null) {
            $event->dispatchVia($dispatcherType);
        }
        return $event;
    }

    /**
     * @testdox T2.11
     */
    public function testDelegatesToEventSpecifiedDispatcher(): void
    {
        $localResult = FakeDispatchResult::success('local-id', 'cmd', 'local');
        $sfnResult = FakeDispatchResult::success('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local'
        );

        $event = $this->createClockAwareEvent('echo test', 'stepfunctions');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertTrue($result->isStarted());
        $this->assertSame('stepfunctions', $result->getDispatcherType());
        $this->assertEquals(1, $sfnDispatcher->getDispatchCount());
        $this->assertEquals(0, $localDispatcher->getDispatchCount());
    }

    /**
     * @testdox T2.12
     */
    public function testUsesDefaultWhenNoEventSetting(): void
    {
        $localResult = FakeDispatchResult::success('local-id', 'cmd', 'local');
        $sfnResult = FakeDispatchResult::success('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local'
        );

        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertTrue($result->isStarted());
        $this->assertSame('local', $result->getDispatcherType());
        $this->assertEquals(1, $localDispatcher->getDispatchCount());
        $this->assertEquals(0, $sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox T2.13
     */
    public function testUsesDefaultWhenDispatcherTypeIsNull(): void
    {
        $localResult = FakeDispatchResult::success('local-id', 'cmd', 'local');
        $sfnResult = FakeDispatchResult::success('sfn-id', 'cmd', 'stepfunctions');

        $localDispatcher = new FakeDispatcher($localResult);
        $sfnDispatcher = new FakeDispatcher($sfnResult);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local'
        );

        $event = $this->createClockAwareEvent('echo test', null);

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertTrue($result->isStarted());
        $this->assertSame('local', $result->getDispatcherType());
        $this->assertEquals(1, $localDispatcher->getDispatchCount());
        $this->assertEquals(0, $sfnDispatcher->getDispatchCount());
    }

    /**
     * @testdox T2.14
     */
    public function testThrowsOnUnknownType(): void
    {
        $localResult = FakeDispatchResult::success('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            'local'
        );

        $event = $this->createClockAwareEvent('echo test', 'unknown');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dispatcher type: unknown. Available types: local');

        $dispatcher->dispatchEvent($event, $this->app);
    }

    /**
     * @testdox T2.15
     */
    public function testReceivesDefaultTypeViaConstructor(): void
    {
        $localResult = FakeDispatchResult::success('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $dispatcher = new CompositeDispatcher(
            ['local' => $localDispatcher],
            'local'
        );

        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox T2.16
     */
    public function testThrowsWhenDispatchersArrayIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dispatchers array cannot be empty');

        new CompositeDispatcher([], 'local');
    }

    /**
     * @testdox T2.17
     */
    public function testThrowsWhenDefaultTypeNotInDispatchers(): void
    {
        $localResult = FakeDispatchResult::success('local-id', 'cmd', 'local');
        $localDispatcher = new FakeDispatcher($localResult);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Default dispatcher type 'nonexistent' not found in dispatchers. Available types: local"
        );

        new CompositeDispatcher(
            ['local' => $localDispatcher],
            'nonexistent'
        );
    }
}
