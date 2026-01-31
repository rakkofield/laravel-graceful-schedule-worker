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
    private const RUN_OUTPUT_FILE = self::SKELETON_PATH . '/storage/logs/schedule-run-output.log';
    private const MAX_WAIT_SECONDS = 10;
    private const POLL_INTERVAL_MICROSECONDS = 100000; // 100ms

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupOutputFile();
    }

    protected function tearDown(): void
    {
        $this->cleanupOutputFile();
        parent::tearDown();
    }

    private function cleanupOutputFile(): void
    {
        if (file_exists(self::RUN_OUTPUT_FILE)) {
            unlink(self::RUN_OUTPUT_FILE);
        }
    }

    private function captureStdoutUntil(Process $process, string $expectedOutput): string
    {
        $stdout = '';
        $process->waitUntil(function ($type, $output) use (&$stdout, $expectedOutput) {
            if (Process::OUT === $type) {
                $stdout .= $output;
            }
            return str_contains($stdout, $expectedOutput);
        });
        return $stdout;
    }

    private function waitForFileContent(string $filePath, string $expectedContent): void
    {
        $startTime = time();
        while (time() - $startTime < self::MAX_WAIT_SECONDS) {
            if (file_exists($filePath)) {
                $fileContent = file_get_contents($filePath);
                if ($fileContent !== false && str_contains($fileContent, $expectedContent)) {
                    return;
                }
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        // Timeout - provide diagnostic information
        $fileExists = file_exists($filePath);
        $actualContent = $fileExists ? file_get_contents($filePath) : 'N/A';

        $this->fail(sprintf(
            "Timeout waiting for expected content in file.\nFile: %s\nExists: %s\nExpected: %s\nActual content:\n%s",
            $filePath,
            $fileExists ? 'yes' : 'no',
            $expectedContent,
            $actualContent
        ));
    }

    /**
     * @testdox T4.1
     */
    public function testRunsScheduledTasks(): void
    {
        $command = Application::formatCommandString('schedule:graceful-work');
        $process = Process::fromShellCommandline($command, self::SKELETON_PATH);

        $process->start();
        $stdout = $this->captureStdoutUntil($process, 'hello finished.');

        $this->assertStringContainsString('Running scheduled tasks.', $stdout);
        $this->assertStringContainsString("Running scheduled command: '" . PHP_BINARY . "' 'artisan' hello >> '" . realpath(self::SKELETON_LOG_PATH) . "' 2>&1", $stdout);
        $this->assertStringContainsString('hello start from Scheduler.', $stdout);
        $this->assertStringContainsString('hello successful.', $stdout);
        $this->assertStringContainsString('hello finished.', $stdout);
    }

    /**
     * @testdox T4.2
     */
    public function testRedirectsOutputToFile(): void
    {
        $command = Application::formatCommandString('schedule:graceful-work') . ' --run-output-file=' . escapeshellarg(self::RUN_OUTPUT_FILE);
        $process = Process::fromShellCommandline($command, self::SKELETON_PATH);

        $process->start();
        $this->waitForFileContent(self::RUN_OUTPUT_FILE, 'hello finished.');

        // Collect stdout and stop the process
        $stdout = $process->getOutput();
        if ($process->isRunning()) {
            $process->stop(3, SIGTERM);
        }

        // Verify stdout contains only graceful-work output, not schedule:run output
        $this->assertStringContainsString('Running scheduled tasks.', $stdout);
        $this->assertStringNotContainsString('hello start from Scheduler.', $stdout);
        $this->assertStringNotContainsString('hello successful.', $stdout);
        $this->assertStringNotContainsString('hello finished.', $stdout);

        // Verify output file contains schedule:run output and logs
        $this->assertFileExists(self::RUN_OUTPUT_FILE);
        $fileContent = file_get_contents(self::RUN_OUTPUT_FILE);
        $this->assertStringContainsString("Running scheduled command: '" . PHP_BINARY . "' 'artisan' hello >> '" . realpath(self::SKELETON_LOG_PATH) . "' 2>&1", $fileContent);
        $this->assertStringContainsString('hello start from Scheduler.', $fileContent);
        $this->assertStringContainsString('hello successful.', $fileContent);
        $this->assertStringContainsString('hello finished.', $fileContent);
    }
}
