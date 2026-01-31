<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\ProcessUtils;
use Symfony\Component\Process\Process;

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

        $lastExecutionStartedAt = Carbon::now()->subMinutes(10);
        /** @var array<Process> $executions */
        $executions = [];

        $command = Application::formatCommandString('schedule:run');

        if ($runOutputFile) {
            $command .= ' >> ' . ProcessUtils::escapeArgument($runOutputFile) . ' 2>&1';
        }

        $this->listenForSignal();

        while ($this->running) {
            usleep(100 * 1000);

            if (
                Carbon::now()->second === 0 &&
                ! Carbon::now()->startOfMinute()->equalTo($lastExecutionStartedAt)
            ) {
                $execution = Process::fromShellCommandline($command);
                $execution->setTimeout(null); // Disable timeout for cron-like behavior

                try {
                    $execution->start(function ($type, $buffer) {
                        /** @var string $buffer */
                        $this->output->write($buffer);
                    });
                    $executions[] = $execution;
                    $lastExecutionStartedAt = Carbon::now()->startOfMinute();
                } catch (\Exception $e) {
                    $this->error('Failed to start scheduled task: ' . $e->getMessage());
                }
            }

            // Process management with improved array cleanup
            $completedKeys = [];
            foreach ($executions as $key => $execution) {
                if (! $execution->isRunning()) {
                    $completedKeys[] = $key;
                }
            }

            // Remove completed processes and rebuild array to prevent memory leaks
            foreach ($completedKeys as $key) {
                unset($executions[$key]);
            }

            if ($completedKeys !== []) {
                $executions = array_values($executions); // Rebuild array indices
            }
        }

        foreach ($executions as $execution) {
            if ($execution->isRunning()) {
                $code = $execution->stop();

                $this->info(
                    'Stop scheduled task command: ' . $execution->getCommandLine() . '. Exit code: ' . $code
                );
            }
        }

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
