<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\Sleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\LocalDispatchResultFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResultFactory;
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
        $this->registerSkippedResultFactory();
        $this->registerLocalDispatcher();
        $this->registerCompositeDispatcher();
        $this->registerTrackingDispatcher();
    }

    private function registerSkippedResultFactory(): void
    {
        $this->container->singleton(SkippedDispatchResultFactory::class, function (Container $app) {
            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            return new SkippedDispatchResultFactory($clock);
        });
    }

    private function registerLocalDispatcher(): void
    {
        $basePath = $this->basePath;
        $this->container->singleton(LocalDispatcher::class, function (Container $app) use ($basePath) {
            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            /** @var ClockInterface $clock */
            $clock = $app->make(ClockInterface::class);

            /** @var SkippedDispatchResultFactory $skippedResultFactory */
            $skippedResultFactory = $app->make(SkippedDispatchResultFactory::class);

            $processManager = new RunningProcessManager($app, $logger, new Sleeper(10000), 10.0);
            $resultFactory = new LocalDispatchResultFactory($clock, $skippedResultFactory);

            return new LocalDispatcher(
                $app,
                $basePath,
                $logger,
                $processManager,
                $resultFactory
            );
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

            /** @var SkippedDispatchResultFactory $skippedResultFactory */
            $skippedResultFactory = $app->make(SkippedDispatchResultFactory::class);

            return new TrackingDispatcher(
                $compositeDispatcher,
                $tracker,
                $logger,
                $skippedResultFactory
            );
        });
    }
}
