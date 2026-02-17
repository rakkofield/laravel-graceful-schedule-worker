<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Orchestrator;

use Illuminate\Contracts\Foundation\Application;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class StubThrowingOrchestrator implements ScheduleOrchestratorInterface
{
    /** @var \Throwable */
    private $throwable;

    /**
     * @param \Throwable $throwable
     */
    public function __construct(\Throwable $throwable)
    {
        $this->throwable = $throwable;
    }

    /**
     * {@inheritdoc}
     */
    public function run(ClockAwareSchedule $schedule, Application $app, callable $shouldContinue): bool
    {
        throw $this->throwable;
    }
}
