<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Illuminate\Contracts\Foundation\Application;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

interface ScheduleOrchestratorInterface
{
    /**
     * Orchestrate and execute scheduled tasks.
     *
     * @param ClockAwareSchedule $schedule Schedule object
     * @param Application $app Laravel application instance
     * @param callable $shouldContinue Function to determine whether to continue execution
     * @return bool Whether execution was successful
     */
    public function run(ClockAwareSchedule $schedule, Application $app, callable $shouldContinue): bool;
}
