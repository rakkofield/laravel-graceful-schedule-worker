<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\Sleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Console\ExceptionReporterInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Console\LegacyExceptionReporter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * Registers ScheduleOrchestratorInterface and ExceptionReporterInterface bindings.
 */
class OrchestratorServiceRegistrar
{
    /** @var Container */
    private $container;

    /** @var string */
    private $appVersion;

    /**
     * @param Container $container
     * @param string $appVersion
     */
    public function __construct(Container $container, string $appVersion)
    {
        $this->container = $container;
        $this->appVersion = $appVersion;
    }

    /**
     * Register orchestrator and exception reporter bindings.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerOrchestrator();
        $this->registerExceptionReporter();
    }

    private function registerOrchestrator(): void
    {
        $this->container->singleton(ScheduleOrchestratorInterface::class, function (Container $app) {
            /** @var ScheduleDispatcherInterface $dispatcher */
            $dispatcher = $app->make(ScheduleDispatcherInterface::class);
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);
            /** @var ExecutionTrackerInterface $tracker */
            $tracker = $app->make(ExecutionTrackerInterface::class);

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            return new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, $logger, new Sleeper(100000));
        });
    }

    /**
     * Laravel 6: ExceptionHandler::report(Exception), Laravel 7+: report(Throwable)
     */
    private function registerExceptionReporter(): void
    {
        $appVersion = $this->appVersion;
        $this->container->singleton(ExceptionReporterInterface::class, function (Container $app) use ($appVersion) {
            /** @var ExceptionHandler $handler */
            $handler = $app->make(ExceptionHandler::class);
            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            if (version_compare($appVersion, '7.0.0', '<')) {
                return new LegacyExceptionReporter($handler, $logger);
            }
            return new ExceptionReporter($handler, $logger);
        });
    }
}
