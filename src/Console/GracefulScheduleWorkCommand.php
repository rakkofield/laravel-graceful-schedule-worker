<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;

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

    /** @var ScheduleOrchestratorInterface */
    private $orchestrator;

    /** @var Schedule */
    private $schedule;

    /**
     * @param ScheduleOrchestratorInterface $orchestrator
     * @param Schedule $schedule
     */
    public function __construct(ScheduleOrchestratorInterface $orchestrator, Schedule $schedule)
    {
        parent::__construct();
        $this->orchestrator = $orchestrator;
        $this->schedule = $schedule;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Running scheduled tasks.');

        $this->listenForSignal();

        /** @var Application $app */
        $app = $this->laravel;

        $this->orchestrator->run(
            $this->schedule,
            $app,
            function () {
                return $this->running;
            }
        );

        return 0;
    }

    private function listenForSignal(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGINT, [$this, 'shutdown']);
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
    }

    /**
     * @param int $signal
     * @param mixed $siginfo
     */
    private function shutdown($signal, $siginfo): void
    {
        unset($siginfo);

        $this->info('Handled signal: ' . $signal);

        $this->running = false;
    }
}
