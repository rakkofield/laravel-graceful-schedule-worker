<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Providers;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

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

        // Create a minimal container
        $this->app = new Container();
        Container::setInstance($this->app);

        // Bind config
        $this->app->singleton('config', function () {
            return new class {
                private $config = [
                    'graceful-scheduler' => [
                        'dispatch' => 'local',
                    ],
                ];

                public function get($key, $default = null) {
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

                public function set($key, $value = null) {
                    if (is_array($key)) {
                        foreach ($key as $k => $v) {
                            $this->setOne($k, $v);
                        }
                    } else {
                        $this->setOne($key, $value);
                    }
                }

                private function setOne($key, $value) {
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

        // Bind required dependencies for Schedule
        $this->app->bind(EventMutex::class, function () {
            return $this->createMock(CacheEventMutex::class);
        });

        $this->app->bind(SchedulingMutex::class, function () {
            return $this->createMock(CacheSchedulingMutex::class);
        });

        // Create the provider
        $this->provider = new GracefulScheduleWorkerProvider($this->app);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * ClockInterface が SystemClock として登録される
     *
     * @test
     */
    public function it_registers_clock_interface_as_singleton()
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ClockInterface::class));
        $this->assertTrue($this->app->isShared(ClockInterface::class));

        $clock = $this->app->make(ClockInterface::class);
        $this->assertInstanceOf(SystemClock::class, $clock);
    }

    /**
     * ClockInterface は同じインスタンスを返す（シングルトン）
     *
     * @test
     */
    public function clock_interface_returns_same_instance()
    {
        $this->provider->register();

        $clock1 = $this->app->make(ClockInterface::class);
        $clock2 = $this->app->make(ClockInterface::class);

        $this->assertSame($clock1, $clock2);
    }

    /**
     * ClockAwareSchedule がシングルトンとして登録される
     *
     * @test
     */
    public function it_registers_clock_aware_schedule_as_singleton()
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ClockAwareSchedule::class));
        $this->assertTrue($this->app->isShared(ClockAwareSchedule::class));

        $schedule = $this->app->make(ClockAwareSchedule::class);
        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * ClockAwareSchedule は同じインスタンスを返す（シングルトン）
     *
     * @test
     */
    public function clock_aware_schedule_returns_same_instance()
    {
        $this->provider->register();

        $schedule1 = $this->app->make(ClockAwareSchedule::class);
        $schedule2 = $this->app->make(ClockAwareSchedule::class);

        $this->assertSame($schedule1, $schedule2);
    }

    /**
     * ClockAwareSchedule は ClockInterface を注入される
     *
     * @test
     */
    public function clock_aware_schedule_receives_clock_interface()
    {
        $this->provider->register();

        $schedule = $this->app->make(ClockAwareSchedule::class);
        $clock = $this->app->make(ClockInterface::class);

        // ClockAwareSchedule が同じ Clock インスタンスを使用していることを確認
        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
        $this->assertInstanceOf(SystemClock::class, $clock);
    }

    /**
     * Schedule が ClockAwareSchedule に extend される
     *
     * @test
     */
    public function it_extends_schedule_to_clock_aware_schedule()
    {
        // 元の Schedule を登録
        $this->app->singleton(Schedule::class, function ($app) {
            return new Schedule();
        });

        $this->provider->register();

        // Schedule を解決すると ClockAwareSchedule が返される
        $schedule = $this->app->make(Schedule::class);
        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * LocalDispatcher がシングルトンとして登録される
     *
     * @test
     */
    public function it_registers_local_dispatcher_as_singleton()
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(LocalDispatcher::class));
        $this->assertTrue($this->app->isShared(LocalDispatcher::class));

        $dispatcher = $this->app->make(LocalDispatcher::class);
        $this->assertInstanceOf(LocalDispatcher::class, $dispatcher);
    }

    /**
     * ScheduleDispatcherInterface が CompositeDispatcher として登録される
     *
     * @test
     */
    public function it_registers_schedule_dispatcher_interface_as_composite()
    {
        $this->provider->register();

        $this->assertTrue($this->app->bound(ScheduleDispatcherInterface::class));
        $this->assertTrue($this->app->isShared(ScheduleDispatcherInterface::class));

        $dispatcher = $this->app->make(ScheduleDispatcherInterface::class);
        $this->assertInstanceOf(CompositeDispatcher::class, $dispatcher);
    }

    /**
     * CompositeDispatcher は同じインスタンスを返す（シングルトン）
     *
     * @test
     */
    public function composite_dispatcher_returns_same_instance()
    {
        $this->provider->register();

        $dispatcher1 = $this->app->make(ScheduleDispatcherInterface::class);
        $dispatcher2 = $this->app->make(ScheduleDispatcherInterface::class);

        $this->assertSame($dispatcher1, $dispatcher2);
    }
}
