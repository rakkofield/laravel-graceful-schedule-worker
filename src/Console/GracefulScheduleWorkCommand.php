<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class GracefulScheduleWorkCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedule:graceful-work';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the schedule worker';

    /** @var bool */
    private $running = true;

    /**
     * Execute the console command.
     *
     * @param ScheduleOrchestratorInterface $orchestrator
     * @param ClockAwareSchedule $schedule
     * @param ExceptionReporterInterface $reporter
     * @return int
     */
    public function handle(
        ScheduleOrchestratorInterface $orchestrator,
        ClockAwareSchedule $schedule,
        ExceptionReporterInterface $reporter
    ) {
        $this->info('Running scheduled tasks.');

        $this->listenForSignal();

        /** @var Application $app */
        $app = $this->laravel;

        try {
            $orchestrator->run(
                $schedule,
                $app,
                function () {
                    return $this->running;
                }
            );
        } catch (\Throwable $e) {
            $this->error('Schedule worker terminated due to an error: ' . $e->getMessage());
            $reporter->report($e);

            return 1;
        }

        return 0;
    }

    private function listenForSignal(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGINT, [$this, 'shutdown']);
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
    }

    /**
     * Handle shutdown signal.
     *
     * This method must be public because it is registered as a callback for pcntl_signal().
     * When PHP receives a signal, it calls this method from external context,
     * which requires public visibility.
     *
     * @param int $signal
     * @param mixed $siginfo
     */
    public function shutdown($signal, $siginfo): void
    {
        unset($siginfo);

        $this->info('Handled signal: ' . $signal);

        $this->running = false;
    }
}
