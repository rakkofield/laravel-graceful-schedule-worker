<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\Sleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\RunningProcessManager;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\TrackingDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * Registers dispatcher bindings: LocalDispatcher, CompositeDispatcher, TrackingDispatcher.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Service registrar necessarily references many classes
 */
class DispatcherServiceRegistrar
{
    /** @var Container */
    private $container;

    /** @var string|null */
    private $basePath;

    /**
     * @param Container $container
     * @param string|null $basePath
     */
    public function __construct(Container $container, ?string $basePath)
    {
        $this->container = $container;
        $this->basePath = $basePath;
    }

    /**
     * Register dispatcher bindings.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerLocalDispatcher();
        $this->registerCompositeDispatcher();
        $this->registerTrackingDispatcher();
    }

    private function registerLocalDispatcher(): void
    {
        $basePath = $this->basePath;
        $this->container->singleton(LocalDispatcher::class, function (Container $app) use ($basePath) {
            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $processManager = new RunningProcessManager($app, $logger, new Sleeper(10000), 10.0);

            $skippedResultFactory = function (
                string $eventIdentifier,
                string $eventCommand,
                DateTimeImmutable $dispatchedAt
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    'withoutOverlapping',
                    $dispatchedAt,
                    DispatcherType::LOCAL
                );
            };

            return new LocalDispatcher($app, $basePath, $logger, $clock, $processManager, $skippedResultFactory);
        });
    }

    private function registerCompositeDispatcher(): void
    {
        $this->container->singleton(CompositeDispatcher::class, function (Container $app) {
            /** @var LocalDispatcher $localDispatcher */
            $localDispatcher = $app->make(LocalDispatcher::class);

            $dispatchers = [
                DispatcherType::LOCAL => $localDispatcher,
            ];

            if ($app->bound(StepFunctionsDispatcher::class) && $app->bound('config')) {
                /** @var ConfigRepository $sfConfig */
                $sfConfig = $app->make('config');
                /** @var string|null $sfArn */
                $sfArn = $sfConfig->get('graceful-scheduler.stepfunctions.state_machine_arn', '');
                $sfArn = (string) $sfArn;
                if ($sfArn !== '') {
                    /** @var StepFunctionsDispatcher $stepFunctionsDispatcher */
                    $stepFunctionsDispatcher = $app->make(StepFunctionsDispatcher::class);
                    $dispatchers[DispatcherType::STEP_FUNCTIONS] = $stepFunctionsDispatcher;
                }
            }

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            return new CompositeDispatcher($dispatchers, $logger);
        });
    }

    private function registerTrackingDispatcher(): void
    {
        $this->container->singleton(ScheduleDispatcherInterface::class, function (Container $app) {
            /** @var CompositeDispatcher $compositeDispatcher */
            $compositeDispatcher = $app->make(CompositeDispatcher::class);
            /** @var ExecutionTrackerInterface $tracker */
            $tracker = $app->make(ExecutionTrackerInterface::class);

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            $skippedResultFactory = function (
                string $eventIdentifier,
                string $eventCommand,
                string $reason,
                DateTimeImmutable $dispatchedAt,
                string $dispatcherType
            ) {
                return new SkippedDispatchResult(
                    $eventIdentifier,
                    $eventCommand,
                    $reason,
                    $dispatchedAt,
                    $dispatcherType
                );
            };

            return new TrackingDispatcher(
                $compositeDispatcher,
                $tracker,
                $logger,
                $clock,
                $skippedResultFactory
            );
        });
    }
}
