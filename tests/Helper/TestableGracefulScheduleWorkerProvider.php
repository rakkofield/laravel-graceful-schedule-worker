<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider;

/**
 * Testable ServiceProvider that skips mergeConfigFrom
 */
class TestableGracefulScheduleWorkerProvider extends GracefulScheduleWorkerProvider
{
    protected function mergeConfigFrom($path, $key): void
    {
    }
}
