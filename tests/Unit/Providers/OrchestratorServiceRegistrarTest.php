<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\SystemClock;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporterInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Console\LegacyExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Console\SpyExceptionHandler;
use RakkoInc\LaravelGracefulScheduleWorker\FakeApplication;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

class OrchestratorServiceRegistrarTest extends TestCase
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

        // Register dispatcher dependencies via DispatcherServiceRegistrar
        (new DispatcherServiceRegistrar($this->app, '/fake/base/path'))->register();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @testdox OSR.1 Registers ScheduleOrchestratorInterface as singleton
     */
    public function testRegistersOrchestratorAsSingleton(): void
    {
        $registrar = new OrchestratorServiceRegistrar($this->app, '10.0.0');
        $registrar->register();

        $this->assertTrue($this->app->bound(ScheduleOrchestratorInterface::class));
        $this->assertTrue($this->app->isShared(ScheduleOrchestratorInterface::class));

        $orchestrator = $this->app->make(ScheduleOrchestratorInterface::class);
        $this->assertInstanceOf(DefaultScheduleOrchestrator::class, $orchestrator);
    }

    /**
     * @testdox OSR.2 Orchestrator returns same instance
     */
    public function testOrchestratorReturnsSameInstance(): void
    {
        $registrar = new OrchestratorServiceRegistrar($this->app, '10.0.0');
        $registrar->register();

        $o1 = $this->app->make(ScheduleOrchestratorInterface::class);
        $o2 = $this->app->make(ScheduleOrchestratorInterface::class);

        $this->assertSame($o1, $o2);
    }

    /**
     * @testdox OSR.3 Registers ExceptionReporter for Laravel 7+
     */
    public function testRegistersExceptionReporterForLaravel7Plus(): void
    {
        $this->app->singleton(ExceptionHandler::class, function () {
            return new SpyExceptionHandler();
        });

        $registrar = new OrchestratorServiceRegistrar($this->app, '7.0.0');
        $registrar->register();

        $reporter = $this->app->make(ExceptionReporterInterface::class);
        $this->assertInstanceOf(ExceptionReporter::class, $reporter);
    }

    /**
     * @testdox OSR.4 Registers LegacyExceptionReporter for Laravel 6
     */
    public function testRegistersLegacyExceptionReporterForLaravel6(): void
    {
        $this->app->singleton(ExceptionHandler::class, function () {
            return new SpyExceptionHandler();
        });

        $registrar = new OrchestratorServiceRegistrar($this->app, '6.20.44');
        $registrar->register();

        $reporter = $this->app->make(ExceptionReporterInterface::class);
        $this->assertInstanceOf(LegacyExceptionReporter::class, $reporter);
    }

    /**
     * @testdox OSR.5 ExceptionReporterInterface is registered as singleton
     */
    public function testExceptionReporterIsRegisteredAsSingleton(): void
    {
        $this->app->singleton(ExceptionHandler::class, function () {
            return new SpyExceptionHandler();
        });

        $registrar = new OrchestratorServiceRegistrar($this->app, '10.0.0');
        $registrar->register();

        $this->assertTrue($this->app->isShared(ExceptionReporterInterface::class));
    }
}
