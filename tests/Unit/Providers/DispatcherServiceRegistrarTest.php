<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

class DispatcherServiceRegistrarTest extends TestCase
{
    /** @var FakeApplication */
    private $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new FakeApplication();
        Container::setInstance($this->app);

        $this->app->singleton('log', function () {
            return new NullLogger();
        });

        $this->app->singleton(ClockInterface::class, SystemClock::class);
        $this->app->singleton('graceful-scheduler.logger', function (Container $app) {
            return new PrefixedLogger($app->make('log'));
        });
        $this->app->singleton(ExecutionTrackerInterface::class, NullExecutionTracker::class);

        $this->app->bind(EventMutex::class, function () {
            return new FakeEventMutex();
        });
        $this->app->bind(SchedulingMutex::class, function () {
            return new FakeSchedulingMutex();
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox DSR.1 Registers LocalDispatcher as singleton
     */
    public function testRegistersLocalDispatcherAsSingleton(): void
    {
        $registrar = new DispatcherServiceRegistrar($this->app, '/fake/base/path');
        $registrar->register();

        $this->assertTrue($this->app->bound(LocalDispatcher::class));
        $this->assertTrue($this->app->isShared(LocalDispatcher::class));

        $dispatcher = $this->app->make(LocalDispatcher::class);
        $this->assertInstanceOf(LocalDispatcher::class, $dispatcher);
    }

    /**
     * @testdox DSR.2 Registers CompositeDispatcher as singleton
     */
    public function testRegistersCompositeDispatcherAsSingleton(): void
    {
        $registrar = new DispatcherServiceRegistrar($this->app, '/fake/base/path');
        $registrar->register();

        $this->assertTrue($this->app->bound(CompositeDispatcher::class));
        $this->assertTrue($this->app->isShared(CompositeDispatcher::class));

        $dispatcher = $this->app->make(CompositeDispatcher::class);
        $this->assertInstanceOf(CompositeDispatcher::class, $dispatcher);
    }

    /**
     * @testdox DSR.3 Registers ScheduleDispatcherInterface as TrackingDispatcher singleton
     */
    public function testRegistersTrackingDispatcherAsSingleton(): void
    {
        $registrar = new DispatcherServiceRegistrar($this->app, '/fake/base/path');
        $registrar->register();

        $this->assertTrue($this->app->bound(ScheduleDispatcherInterface::class));
        $this->assertTrue($this->app->isShared(ScheduleDispatcherInterface::class));

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);
        $this->assertInstanceOf(TrackingDispatcher::class, $dispatcher);
    }

    /**
     * @testdox DSR.4 LocalDispatcher receives null basePath when not provided
     */
    public function testLocalDispatcherReceivesNullBasePath(): void
    {
        $registrar = new DispatcherServiceRegistrar($this->app, null);
        $registrar->register();

        $dispatcher = $this->app->make(LocalDispatcher::class);
        $this->assertInstanceOf(LocalDispatcher::class, $dispatcher);
    }

    /**
     * @testdox DSR.5 ScheduleDispatcherInterface returns same instance
     */
    public function testScheduleDispatcherInterfaceReturnsSameInstance(): void
    {
        $registrar = new DispatcherServiceRegistrar($this->app, '/fake/base/path');
        $registrar->register();

        $d1 = $this->app->make(ScheduleDispatcherInterface::class);
        $d2 = $this->app->make(ScheduleDispatcherInterface::class);

        $this->assertSame($d1, $d2);
    }
}
