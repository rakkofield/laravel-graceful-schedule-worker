<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Integration\Console;

use Illuminate\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GracefulScheduleWorkerCommandTest extends TestCase
{
    private const SKELETON_PATH = __DIR__ . '/../../../skeleton';
    private const SKELETON_LOG_PATH = self::SKELETON_PATH . '/storage/logs/scheduler.log';

    public function testCommand(): void
    {
        $command = Application::formatCommandString('schedule:graceful-work');
        $process = Process::fromShellCommandline($command, self::SKELETON_PATH);
        $stdout = '';

        $process->start();
        $process->waitUntil(function ($type, $output) use (&$stdout) {
            if (Process::OUT === $type) {
                $stdout .= $output;
            }

            return str_contains($output, 'hello finished.');
        });

        $this->assertStringContainsString('Running scheduled tasks.', $stdout);
        $this->assertStringContainsString("Running scheduled command: '" . PHP_BINARY . "' 'artisan' hello >> '" . realpath(self::SKELETON_LOG_PATH) . "' 2>&1", $stdout);
        $this->assertStringContainsString('hello start from Scheduler.', $stdout);
        $this->assertStringContainsString('hello successful.', $stdout);
        $this->assertStringContainsString('hello finished.', $stdout);
    }
}
