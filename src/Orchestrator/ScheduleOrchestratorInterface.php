<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;

interface ScheduleOrchestratorInterface
{
    /**
     * Orchestrate and execute scheduled tasks.
     *
     * @param Schedule $schedule Laravel schedule object
     * @param Application $app Laravel application instance
     * @param callable $shouldContinue Function to determine whether to continue execution
     * @return bool Whether execution was successful
     */
    public function run(Schedule $schedule, Application $app, callable $shouldContinue): bool;
}
