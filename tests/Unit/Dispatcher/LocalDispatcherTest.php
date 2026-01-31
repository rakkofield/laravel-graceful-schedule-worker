<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;

class LocalDispatcherTest extends TestCase
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

    /**
     * @testdox T2.1
     */
    public function testReturnsLocalDispatchResult(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(LocalDispatchResult::class, $result);
    }

    /**
     * @testdox T2.2
     */
    public function testReturnsStartedTrueOnSuccess(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($result->isStarted());
    }

    /**
     * @testdox T2.3
     */
    public function testReturnsCorrectEventIdentifier(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
    }

    /**
     * @testdox T2.4
     */
    public function testReturnsCorrectEventCommand(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame('php artisan report:daily', $result->getEventCommand());
    }

    /**
     * @testdox T2.5
     */
    public function testReturnsCorrectDispatcherType(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox T2.6
     */
    public function testStartsProcessInBackground(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('sleep 0.1');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($result->isStarted());
        $this->assertNotNull($result->getProcess());

        $result->getProcess()->wait();
    }

    /**
     * @testdox T2.7
     */
    public function testReturnsDispatchedAtTimestamp(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $before = new \DateTimeImmutable();
        $result = $dispatcher->dispatchEvent($event, $this->app);
        $after = new \DateTimeImmutable();

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertGreaterThanOrEqual($before, $dispatchedAt);
        $this->assertLessThanOrEqual($after, $dispatchedAt);
    }

    /**
     * @testdox T2.8
     */
    public function testReturnsNoErrorOnSuccess(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertNull($result->getError());
    }

    /**
     * @testdox T2.10
     */
    public function testHasRunningProcessImmediatelyAfterDispatch(): void
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createEvent('sleep 2');

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($result->isRunning());

        $result->getProcess()->stop(0);
    }
}
