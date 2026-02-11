<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporterInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Console\LegacyExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Console\SpyExceptionHandler;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeNonLockProviderStore;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

class GracefulScheduleWorkerProviderTest extends TestCase
{
    /**
     * @var FakeApplication
     */
    private $app;

    /**
     * @var GracefulScheduleWorkerProvider
     */
    private $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new FakeApplication();
        Container::setInstance($this->app);

        $this->app->singleton('config', function () {
            return new class {
                /** @var array<string, mixed> */
                private $config = [
                    'graceful-scheduler' => [
                        'dispatch' => 'local',
                    ],
                ];

                /**
                 * @param string $key
                 * @param mixed $default
                 * @return mixed
                 */
                public function get(string $key, $default = null)
                {
                    $keys = explode('.', $key);
                    $value = $this->config;

                    foreach ($keys as $k) {
                        if (!isset($value[$k])) {
                            return $default;
                        }
                        $value = $value[$k];
                    }

                    return $value;
                }

                /**
                 * @param string|array<string, mixed> $key
                 * @param mixed $value
                 * @return void
                 */
                public function set($key, $value = null): void
                {
                    if (is_array($key)) {
                        foreach ($key as $k => $v) {
                            $this->setOne($k, $v);
                        }
                    } else {
                        $this->setOne($key, $value);
                    }
                }

                /**
                 * @param string $key
                 * @param mixed $value
                 * @return void
                 */
                private function setOne(string $key, $value): void
                {
                    $keys = explode('.', $key);
                    $config = &$this->config;

                    foreach ($keys as $k) {
                        if (!isset($config[$k]) || !is_array($config[$k])) {
                            $config[$k] = [];
                        }
                        $config = &$config[$k];
                    }

                    $config = $value;
                }
            };
        });

        $this->app->bind(EventMutex::class, function () {
            return new FakeEventMutex();
        });

        $this->app->bind(SchedulingMutex::class, function () {
            return new FakeSchedulingMutex();
        });

        $this->provider = new TestableGracefulScheduleWorkerProvider($this->app);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox GP.1 Registers ClockInterface as a singleton
     */
    public function testRegistersClockInterfaceAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ClockInterface::class));
        $this->assertTrue($this->app->isShared(ClockInterface::class));

        $clock = $this->app->make(ClockInterface::class);
        $this->assertInstanceOf(SystemClock::class, $clock);
    }

    /**
     * @testdox GP.2 ClockInterface returns the same instance
     */
    public function testClockInterfaceReturnsSameInstance(): void
    {
        $this->provider->register();

        $clock1 = $this->app->make(ClockInterface::class);
        $clock2 = $this->app->make(ClockInterface::class);

        $this->assertSame($clock1, $clock2);
    }

    /**
     * @testdox GP.3 ClockAwareSchedule receives ClockInterface
     */
    public function testClockAwareScheduleReceivesClockInterface(): void
    {
        $this->provider->register();

        $schedule = $this->app->make(ClockAwareSchedule::class);
        $clock = $this->app->make(ClockInterface::class);

        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
        $this->assertInstanceOf(SystemClock::class, $clock);
    }

    /**
     * @testdox GP.4 Registers LocalDispatcher as a singleton
     */
    public function testRegistersLocalDispatcherAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(LocalDispatcher::class));
        $this->assertTrue($this->app->isShared(LocalDispatcher::class));

        $dispatcher = $this->app->make(LocalDispatcher::class);
        $this->assertInstanceOf(LocalDispatcher::class, $dispatcher);
    }

    /**
     * @testdox GP.5 Registers ScheduleDispatcherInterface as TrackingDispatcher
     */
    public function testRegistersScheduleDispatcherInterfaceAsTrackingDispatcher(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ScheduleDispatcherInterface::class));
        $this->assertTrue($this->app->isShared(ScheduleDispatcherInterface::class));

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);
        $this->assertInstanceOf(TrackingDispatcher::class, $dispatcher);
    }

    /**
     * @testdox GP.6 ScheduleDispatcherInterface returns the same instance
     */
    public function testCompositeDispatcherReturnsSameInstance(): void
    {
        $this->provider->register();

        $dispatcher1 = $this->app->make(ScheduleDispatcherInterface::class);
        $dispatcher2 = $this->app->make(ScheduleDispatcherInterface::class);

        $this->assertSame($dispatcher1, $dispatcher2);
    }

    /**
     * @testdox GP.7 Returns TrackingDispatcher when dispatch config is 'local'
     */
    public function testScheduleDispatcherInterfaceReturnsTrackingDispatcher(): void
    {
        $this->app->make('config')->set('graceful-scheduler.dispatch', 'local');

        $this->provider->register();

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);
        $this->assertInstanceOf(TrackingDispatcher::class, $dispatcher);
    }

    /**
     * @testdox GP.8 Registers ScheduleOrchestratorInterface as a singleton
     */
    public function testRegistersScheduleOrchestratorInterfaceAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ScheduleOrchestratorInterface::class));
        $this->assertTrue($this->app->isShared(ScheduleOrchestratorInterface::class));

        $orchestrator = $this->app->make(ScheduleOrchestratorInterface::class);
        $this->assertInstanceOf(DefaultScheduleOrchestrator::class, $orchestrator);
    }

    /**
     * @testdox GP.9 ScheduleOrchestrator returns the same instance
     */
    public function testScheduleOrchestratorReturnsSameInstance(): void
    {
        $this->provider->register();

        $orchestrator1 = $this->app->make(ScheduleOrchestratorInterface::class);
        $orchestrator2 = $this->app->make(ScheduleOrchestratorInterface::class);

        $this->assertSame($orchestrator1, $orchestrator2);
    }

    /**
     * @testdox GP.10 Registers ExecutionTrackerInterface when enabled
     */
    public function testRegistersExecutionTrackerInterfaceWhenEnabled(): void
    {
        // Setup cache mock with LockProvider
        $lockProvider = new FakeLockProvider();
        $cacheStore = new FakeCacheStore($lockProvider);
        $this->app->singleton('cache', function () use ($cacheStore) {
            return new class ($cacheStore) {
                /** @var FakeCacheStore */
                private $store;

                /**
                 * @param FakeCacheStore $store
                 */
                public function __construct(FakeCacheStore $store)
                {
                    $this->store = $store;
                }

                /**
                 * @param string|null $name
                 * @return FakeCacheStore
                 */
                public function store($name = null)
                {
                    return $this->store;
                }
            };
        });

        // Enable tracker in config
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', true);

        $this->provider->register();

        $this->assertTrue($this->app->bound(ExecutionTrackerInterface::class));
        $this->assertTrue($this->app->isShared(ExecutionTrackerInterface::class));

        $tracker = $this->app->make(ExecutionTrackerInterface::class);
        $this->assertInstanceOf(CacheExecutionTracker::class, $tracker);
    }

    /**
     * @testdox GP.11 Registers NullExecutionTracker when disabled
     */
    public function testRegistersNullExecutionTrackerWhenDisabled(): void
    {
        // tracker.enabled is false by default
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', false);

        $this->provider->register();

        $this->assertTrue($this->app->bound(ExecutionTrackerInterface::class));

        $tracker = $this->app->make(ExecutionTrackerInterface::class);
        $this->assertInstanceOf(NullExecutionTracker::class, $tracker);
    }

    /**
     * @testdox GP.12 Orchestrator receives Tracker when enabled
     */
    public function testOrchestratorReceivesTrackerWhenEnabled(): void
    {
        // Setup cache mock with LockProvider
        $lockProvider = new FakeLockProvider();
        $cacheStore = new FakeCacheStore($lockProvider);
        $this->app->singleton('cache', function () use ($cacheStore) {
            return new class ($cacheStore) {
                /** @var FakeCacheStore */
                private $store;

                /**
                 * @param FakeCacheStore $store
                 */
                public function __construct(FakeCacheStore $store)
                {
                    $this->store = $store;
                }

                /**
                 * @param string|null $name
                 * @return FakeCacheStore
                 */
                public function store($name = null)
                {
                    return $this->store;
                }
            };
        });

        // Enable tracker in config
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', true);

        $this->provider->register();

        $orchestrator = $this->app->make(ScheduleOrchestratorInterface::class);
        $this->assertInstanceOf(DefaultScheduleOrchestrator::class, $orchestrator);

        // Verify tracker property via reflection
        $reflection = new \ReflectionClass($orchestrator);
        $property = $reflection->getProperty('tracker');
        $property->setAccessible(true);
        $tracker = $property->getValue($orchestrator);

        $this->assertInstanceOf(ExecutionTrackerInterface::class, $tracker);
    }

    /**
     * @testdox GP.13 Orchestrator has NullExecutionTracker when disabled
     */
    public function testOrchestratorHasNullExecutionTrackerWhenDisabled(): void
    {
        // tracker.enabled is false by default
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', false);

        $this->provider->register();

        $orchestrator = $this->app->make(ScheduleOrchestratorInterface::class);
        $this->assertInstanceOf(DefaultScheduleOrchestrator::class, $orchestrator);

        // Verify tracker property via reflection
        $reflection = new \ReflectionClass($orchestrator);
        $property = $reflection->getProperty('tracker');
        $property->setAccessible(true);
        $tracker = $property->getValue($orchestrator);

        $this->assertInstanceOf(NullExecutionTracker::class, $tracker);
    }

    /**
     * @testdox GP.14 Returns NullExecutionTracker when config is not bound
     */
    public function testReturnsNullTrackerWhenConfigNotBound(): void
    {
        // Build a new FakeApplication to remove the config binding
        $app = new FakeApplication();
        Container::setInstance($app);

        $app->bind(EventMutex::class, function () {
            return new FakeEventMutex();
        });
        $app->bind(SchedulingMutex::class, function () {
            return new FakeSchedulingMutex();
        });

        $provider = new TestableGracefulScheduleWorkerProvider($app);
        $provider->register();

        $tracker = $app->make(ExecutionTrackerInterface::class);
        $this->assertInstanceOf(NullExecutionTracker::class, $tracker);
    }

    /**
     * @testdox GP.15 Throws RuntimeException when cache store does not implement LockProvider
     */
    public function testThrowsRuntimeExceptionWhenStoreDoesNotImplementLockProvider(): void
    {
        // Use a Store that does not implement LockProvider
        $nonLockProviderStore = new FakeNonLockProviderStore();
        $cacheStore = new FakeCacheStore($nonLockProviderStore);
        $this->app->singleton('cache', function () use ($cacheStore) {
            return new class ($cacheStore) {
                /** @var FakeCacheStore */
                private $store;

                /**
                 * @param FakeCacheStore $store
                 */
                public function __construct(FakeCacheStore $store)
                {
                    $this->store = $store;
                }

                /**
                 * @param string|null $name
                 * @return FakeCacheStore
                 */
                public function store($name = null)
                {
                    return $this->store;
                }
            };
        });

        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', true);

        $this->provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ExecutionTracker requires a cache driver that implements LockProvider');

        $this->app->make(ExecutionTrackerInterface::class);
    }

    /**
     * @testdox GP.16 Registers ExceptionReporterInterface as ExceptionReporter for Laravel 7+
     * @group requires-php74-handler
     */
    public function testRegistersExceptionReporterInterface(): void
    {
        $this->app->setVersion('7.0.0');
        $this->app->singleton(ExceptionHandler::class, function () {
            return new SpyExceptionHandler();
        });

        $this->provider->register();

        $this->assertTrue($this->app->bound(ExceptionReporterInterface::class));
        $this->assertTrue($this->app->isShared(ExceptionReporterInterface::class));

        $reporter = $this->app->make(ExceptionReporterInterface::class);
        $this->assertInstanceOf(ExceptionReporter::class, $reporter);
    }

    /**
     * @testdox GP.17 Registers LegacyExceptionReporter for Laravel 6
     * @group requires-php74-handler
     */
    public function testRegistersLegacyExceptionReporterForLaravel6(): void
    {
        $this->app->setVersion('6.20.44');
        $this->app->singleton(ExceptionHandler::class, function () {
            return new SpyExceptionHandler();
        });

        $this->provider->register();

        $reporter = $this->app->make(ExceptionReporterInterface::class);
        $this->assertInstanceOf(LegacyExceptionReporter::class, $reporter);
    }
}
