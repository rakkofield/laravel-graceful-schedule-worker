<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Providers;

/**
 * Testable ServiceProvider that skips mergeConfigFrom
 */
class TestableGracefulScheduleWorkerProvider extends GracefulScheduleWorkerProvider
{
    protected function mergeConfigFrom($path, $key): void
    {
    }
}
