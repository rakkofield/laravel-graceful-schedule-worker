<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeNonLockProviderStore;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

class TrackerServiceRegistrarTest extends TestCase
{
    /** @var FakeApplication */
    private $app;

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

        $this->app->singleton('log', function () {
            return new NullLogger();
        });

        $this->app->singleton('graceful-scheduler.logger', function (Container $app) {
            return new PrefixedLogger($app->make('log'));
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox TSR.1 Registers NullExecutionTracker when tracker is disabled
     */
    public function testRegistersNullTrackerWhenDisabled(): void
    {
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', false);

        $registrar = new TrackerServiceRegistrar($this->app);
        $registrar->register();

        $tracker = $this->app->make(ExecutionTrackerInterface::class);
        $this->assertInstanceOf(NullExecutionTracker::class, $tracker);
    }

    /**
     * @testdox TSR.2 Registers CacheExecutionTracker when tracker is enabled
     */
    public function testRegistersCacheTrackerWhenEnabled(): void
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

        $registrar = new TrackerServiceRegistrar($this->app);
        $registrar->register();

        $tracker = $this->app->make(ExecutionTrackerInterface::class);
        $this->assertInstanceOf(CacheExecutionTracker::class, $tracker);
    }

    /**
     * @testdox TSR.3 Returns NullExecutionTracker when config is not bound
     */
    public function testReturnsNullTrackerWhenConfigNotBound(): void
    {
        $app = new FakeApplication();
        Container::setInstance($app);

        $registrar = new TrackerServiceRegistrar($app);
        $registrar->register();

        $tracker = $app->make(ExecutionTrackerInterface::class);
        $this->assertInstanceOf(NullExecutionTracker::class, $tracker);
    }

    /**
     * @testdox TSR.4 Throws RuntimeException when cache store does not implement LockProvider
     */
    public function testThrowsRuntimeExceptionForNonLockProviderStore(): void
    {
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

        $registrar = new TrackerServiceRegistrar($this->app);
        $registrar->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ExecutionTracker requires a cache driver that implements LockProvider');

        $this->app->make(ExecutionTrackerInterface::class);
    }

    /**
     * @testdox TSR.5 ExecutionTrackerInterface is registered as singleton
     */
    public function testTrackerIsRegisteredAsSingleton(): void
    {
        $this->app->make('config')->set('graceful-scheduler.tracker.enabled', false);

        $registrar = new TrackerServiceRegistrar($this->app);
        $registrar->register();

        $this->assertTrue($this->app->isShared(ExecutionTrackerInterface::class));
    }
}
