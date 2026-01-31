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
    protected $signature = 'schedule:graceful-work
        {--run-output-file= : The file to direct <info>schedule:run</info> output to}';

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
     * @return 0|1
     */
    public function handle()
    {
        /** @var string|null $runOutputFile */
        $runOutputFile = $this->option('run-output-file');

        $validationError = $this->validateOutputFile($runOutputFile);
        if ($validationError !== null) {
            $this->error($validationError);
            return 1;
        }

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

    /**
     * Validate the output file path.
     *
     * @param string|null $path
     * @return string|null Error message if validation fails, null otherwise
     */
    private function validateOutputFile($path)
    {
        if ($path === null) {
            return null;
        }

        if (file_exists($path)) {
            return is_writable($path) ? null : 'The output file is not writable: ' . $path;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            return 'The directory does not exist: ' . $dir;
        }
        if (! is_writable($dir)) {
            return 'The directory is not writable: ' . $dir;
        }

        return null;
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
