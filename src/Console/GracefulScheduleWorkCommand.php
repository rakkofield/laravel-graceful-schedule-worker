<?php

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
    protected $signature = 'schedule:graceful-work {--run-output-file= : The file to direct <info>schedule:run</info> output to}';

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
     * @return void
     */
    public function handle()
    {
        $this->info('Running scheduled tasks.');

        /** @var $executions array<Process> */
        $lastExecutionStartedAt = Carbon::now()->subMinutes(10);
        $executions = [];

        $command = Application::formatCommandString('schedule:run');

        if ($this->option('run-output-file')) {
            $command .= ' >> ' . ProcessUtils::escapeArgument($this->option('run-output-file')) . ' 2>&1';
        }

        $this->listenForSignal();

        while ($this->running) {
            usleep(100 * 1000);

            if (Carbon::now()->second === 0 &&
                ! Carbon::now()->startOfMinute()->equalTo($lastExecutionStartedAt)) {
                $execution = Process::fromShellCommandline($command);
                $execution->setTimeout(null); // Disable timeout for cron-like behavior

                $execution->start();
                $executions[] = $execution;

                $lastExecutionStartedAt = Carbon::now()->startOfMinute();
            }

            foreach ($executions as $key => $execution) {
                $output = $execution->getIncrementalOutput().
                    $execution->getIncrementalErrorOutput();

                $this->output->write(ltrim($output, "\n"));

                if (! $execution->isRunning()) {
                    unset($executions[$key]);
                }
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
