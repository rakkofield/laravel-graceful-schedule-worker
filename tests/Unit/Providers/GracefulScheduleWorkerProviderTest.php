<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\TestableGracefulScheduleWorkerProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

class GracefulScheduleWorkerProviderTest extends TestCase
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @var GracefulScheduleWorkerProvider
     */
    private $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
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
     * @testdox T3.1
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
     * @testdox T3.2
     */
    public function testClockInterfaceReturnsSameInstance(): void
    {
        $this->provider->register();

        $clock1 = $this->app->make(ClockInterface::class);
        $clock2 = $this->app->make(ClockInterface::class);

        $this->assertSame($clock1, $clock2);
    }

    /**
     * @testdox T3.3
     */
    public function testRegistersClockAwareScheduleAsSingleton(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ClockAwareSchedule::class));
        $this->assertTrue($this->app->isShared(ClockAwareSchedule::class));

        $schedule = $this->app->make(ClockAwareSchedule::class);
        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox T3.4
     */
    public function testClockAwareScheduleReturnsSameInstance(): void
    {
        $this->provider->register();

        $schedule1 = $this->app->make(ClockAwareSchedule::class);
        $schedule2 = $this->app->make(ClockAwareSchedule::class);

        $this->assertSame($schedule1, $schedule2);
    }

    /**
     * @testdox T3.5
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
     * @testdox T3.6
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
     * @testdox T3.7
     */
    public function testRegistersScheduleDispatcherInterfaceAsComposite(): void
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ScheduleDispatcherInterface::class));
        $this->assertTrue($this->app->isShared(ScheduleDispatcherInterface::class));

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);
        $this->assertInstanceOf(CompositeDispatcher::class, $dispatcher);
    }

    /**
     * @testdox T3.8
     */
    public function testCompositeDispatcherReturnsSameInstance(): void
    {
        $this->provider->register();

        $dispatcher1 = $this->app->make(ScheduleDispatcherInterface::class);
        $dispatcher2 = $this->app->make(ScheduleDispatcherInterface::class);

        $this->assertSame($dispatcher1, $dispatcher2);
    }

    /**
     * @testdox T3.9
     */
    public function testCompositeDispatcherUsesConfigForDefaultType(): void
    {
        $this->app->make('config')->set('graceful-scheduler.dispatch', 'local');

        $this->provider->register();

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);
        $this->assertInstanceOf(CompositeDispatcher::class, $dispatcher);
    }

    /**
     * @testdox T3.10
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
     * @testdox T3.11
     */
    public function testScheduleOrchestratorReturnsSameInstance(): void
    {
        $this->provider->register();

        $orchestrator1 = $this->app->make(ScheduleOrchestratorInterface::class);
        $orchestrator2 = $this->app->make(ScheduleOrchestratorInterface::class);

        $this->assertSame($orchestrator1, $orchestrator2);
    }

    /**
     * @testdox T3.12 Registers ExecutionTrackerInterface when enabled
     */
    public function testRegistersExecutionTrackerInterfaceWhenEnabled(): void
    {
        // Setup cache mock
        $cacheStore = new FakeCacheStore();
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
     * @testdox T3.13 Does not register ExecutionTrackerInterface when disabled
     */
    public function testDoesNotRegisterExecutionTrackerInterfaceWhenDisabled(): void
    {
        // tracker.enabled is false by default
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', false);

        $this->provider->register();

        $this->assertFalse($this->app->bound(ExecutionTrackerInterface::class));
    }

    /**
     * @testdox T3.14 Orchestrator receives Tracker when enabled
     */
    public function testOrchestratorReceivesTrackerWhenEnabled(): void
    {
        // Setup cache mock
        $cacheStore = new FakeCacheStore();
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

        // リフレクションで tracker プロパティを確認
        $reflection = new \ReflectionClass($orchestrator);
        $property = $reflection->getProperty('tracker');
        $property->setAccessible(true);
        $tracker = $property->getValue($orchestrator);

        $this->assertInstanceOf(ExecutionTrackerInterface::class, $tracker);
    }

    /**
     * @testdox T3.15 Orchestrator has null Tracker when disabled
     */
    public function testOrchestratorHasNullTrackerWhenDisabled(): void
    {
        // tracker.enabled is false by default
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', false);

        $this->provider->register();

        $orchestrator = $this->app->make(ScheduleOrchestratorInterface::class);
        $this->assertInstanceOf(DefaultScheduleOrchestrator::class, $orchestrator);

        // リフレクションで tracker プロパティを確認
        $reflection = new \ReflectionClass($orchestrator);
        $property = $reflection->getProperty('tracker');
        $property->setAccessible(true);
        $tracker = $property->getValue($orchestrator);

        $this->assertNull($tracker);
    }
}
