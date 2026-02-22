<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Logging\PrefixedLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;
use RuntimeException;

/**
 * Registers ExecutionTrackerInterface binding with cache store validation.
 */
class TrackerServiceRegistrar
{
    /** @var Container */
    private $container;

    /**
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register ExecutionTracker binding.
     *
     * @return void
     */
    public function register(): void
    {
        $this->container->singleton(ExecutionTrackerInterface::class, function (Container $app) {
            if (!$app->bound('config')) {
                return new NullExecutionTracker();
            }

            /** @var ConfigRepository $config */
            $config = $app->make('config');

            if (!$config->get('graceful-scheduler.tracker.enabled', false)) {
                return new NullExecutionTracker();
            }

            /** @var string|null $storeName */
            $storeName = $config->get('graceful-scheduler.tracker.store');

            /** @var \Illuminate\Contracts\Cache\Factory $cacheFactory */
            $cacheFactory = $app->make('cache');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $cacheFactory->store($storeName);

            $store = $cache->getStore();
            if (!$store instanceof LockProvider) {
                throw new RuntimeException(
                    'ExecutionTracker requires a cache driver that implements LockProvider (e.g., Redis, Memcached). ' .
                    'Current driver does not support distributed locking.'
                );
            }

            /** @var int|string $lockTtl */
            $lockTtl = $config->get('graceful-scheduler.tracker.lock_ttl', 3600);
            $lockTtl = (int) $lockTtl;

            /** @var PrefixedLogger $logger */
            $logger = $app->make('graceful-scheduler.logger');

            return new CacheExecutionTracker($cache, $store, $logger, $lockTtl);
        });
    }
}
