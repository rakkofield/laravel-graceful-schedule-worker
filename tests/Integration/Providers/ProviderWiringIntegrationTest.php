<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SleeperInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\TestableGracefulScheduleWorkerProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

/**
 * ServiceProvider が組み立てたコンポーネントチェーンの動作検証
 */
class ProviderWiringIntegrationTest extends TestCase
{
    /** @var Container */
    private $app;

    /** @var GracefulScheduleWorkerProvider */
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
     * @testdox T13.1 Resolved dispatcher can dispatch local event
     */
    public function testResolvedDispatcherCanDispatchLocalEvent(): void
    {
        $this->provider->register();

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);
        $this->assertInstanceOf(TrackingDispatcher::class, $dispatcher);

        $event = new Event(new FakeEventMutex(), 'echo hello');
        $dueAt = new DateTimeImmutable();

        $result = $dispatcher->dispatchEvent($event, $this->app, $dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertSame('local', $result->getDispatcherType());

        $dispatcher->stopAll();
    }

    /**
     * @testdox T13.2 Tracker enabled creates full tracking chain
     */
    public function testTrackerEnabledCreatesFullTrackingChain(): void
    {
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

        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', true);

        $this->provider->register();

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);

        $ref = new \ReflectionClass($dispatcher);
        $prop = $ref->getProperty('tracker');
        $prop->setAccessible(true);
        $tracker = $prop->getValue($dispatcher);

        $this->assertInstanceOf(CacheExecutionTracker::class, $tracker);
    }

    /**
     * @testdox T13.3 Tracker disabled uses NullTracker
     */
    public function testTrackerDisabledUsesNullTracker(): void
    {
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', false);

        $this->provider->register();

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);

        $ref = new \ReflectionClass($dispatcher);
        $prop = $ref->getProperty('tracker');
        $prop->setAccessible(true);
        $tracker = $prop->getValue($dispatcher);

        $this->assertInstanceOf(NullExecutionTracker::class, $tracker);
    }

    /**
     * @testdox T13.4 Orchestrator receives correct dependencies
     */
    public function testOrchestratorReceivesCorrectDependencies(): void
    {
        $this->provider->register();

        $orchestrator = $this->app->make(ScheduleOrchestratorInterface::class);
        $this->assertInstanceOf(DefaultScheduleOrchestrator::class, $orchestrator);

        $ref = new \ReflectionClass($orchestrator);

        $dispatcherProp = $ref->getProperty('dispatcher');
        $dispatcherProp->setAccessible(true);
        $this->assertInstanceOf(TrackingDispatcher::class, $dispatcherProp->getValue($orchestrator));

        $clockProp = $ref->getProperty('clock');
        $clockProp->setAccessible(true);
        $this->assertInstanceOf(SystemClock::class, $clockProp->getValue($orchestrator));

        $trackerProp = $ref->getProperty('tracker');
        $trackerProp->setAccessible(true);
        $this->assertInstanceOf(ExecutionTrackerInterface::class, $trackerProp->getValue($orchestrator));

        $loggerProp = $ref->getProperty('logger');
        $loggerProp->setAccessible(true);
        $this->assertInstanceOf(LoggerInterface::class, $loggerProp->getValue($orchestrator));

        $sleeperProp = $ref->getProperty('sleeper');
        $sleeperProp->setAccessible(true);
        $this->assertInstanceOf(SleeperInterface::class, $sleeperProp->getValue($orchestrator));
    }
}
