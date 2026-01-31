<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Integration tests for GracefulScheduleWorkCommand.
 *
 * These tests launch actual processes using the skeleton application.
 * They require PHP 8.4 or lower due to skeleton's Laravel 7.x dependency.
 *
 * @group integration
 */
final class GracefulScheduleWorkerCommandTest extends TestCase
{
    private const SKELETON_PATH = __DIR__ . '/../../../skeleton';
    private const SKELETON_LOG_PATH = self::SKELETON_PATH . '/storage/logs/scheduler.log';
    private const MAX_WAIT_SECONDS = 10;
    private const POLL_INTERVAL_MICROSECONDS = 100000; // 100ms

    protected function setUp(): void
    {
        parent::setUp();

        // Check the PHP binary that formatCommandString() will use
        // It uses PhpExecutableFinder, not PHP_BINARY
        $finder = new PhpExecutableFinder();
        $phpBinary = $finder->find(false);

        if ($phpBinary !== false) {
            $versionOutput = shell_exec(escapeshellarg($phpBinary) . ' -r "echo PHP_VERSION_ID;"');
            $binaryVersionId = (int) trim($versionOutput);

            // Skip tests on PHP 8.5+ due to skeleton's Laravel 7.x incompatibility
            if ($binaryVersionId >= 80500) {
                $this->markTestSkipped('Integration tests require PHP 8.4 or lower (skeleton uses Laravel 7.x)');
            }
        }
    }

    /**
     * @param Process $process
     * @param string $expectedOutput
     * @return string
     */
    private function captureStdoutUntil(Process $process, string $expectedOutput): string
    {
        $stdout = '';
        $startTime = time();

        while (time() - $startTime < self::MAX_WAIT_SECONDS) {
            $stdout .= $process->getIncrementalOutput();

            if (str_contains($stdout, $expectedOutput)) {
                return $stdout;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        // Timeout
        $this->fail(sprintf(
            "Timeout waiting for expected output.\nExpected: %s\nActual stdout:\n%s",
            $expectedOutput,
            $stdout
        ));
    }

    /**
     * @param string $filePath
     * @param string $expectedContent
     * @return void
     */
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
     * @testdox T4.1 Orchestrator dispatches events via LocalDispatcher
     */
    public function testOrchestratorDispatchesEventsViaLocalDispatcher(): void
    {
        // Clean up log file before test
        if (file_exists(self::SKELETON_LOG_PATH)) {
            unlink(self::SKELETON_LOG_PATH);
        }

        $command = Application::formatCommandString('schedule:graceful-work');
        $process = Process::fromShellCommandline($command, self::SKELETON_PATH);

        $process->start();
        $stdout = $this->captureStdoutUntil($process, 'Running scheduled tasks.');

        // Wait for the scheduled command to be executed and write to log file
        $this->waitForFileContent(self::SKELETON_LOG_PATH, 'Hello World! from log');

        // Stop the process
        if ($process->isRunning()) {
            $process->stop(3, SIGTERM);
        }

        // Verify command output
        $this->assertStringContainsString('Running scheduled tasks.', $stdout);

        // Verify the scheduled command was executed (check log file)
        $logContent = file_get_contents(self::SKELETON_LOG_PATH);
        $this->assertStringContainsString('Hello World! from log', $logContent);
    }

    /**
     * @testdox T4.2 Graceful shutdown stops gracefully on SIGTERM
     */
    public function testGracefulShutdownStopsGracefullyOnSigterm(): void
    {
        $command = Application::formatCommandString('schedule:graceful-work');
        $process = Process::fromShellCommandline($command, self::SKELETON_PATH);

        $process->start();

        // Wait for process to start
        $this->captureStdoutUntil($process, 'Running scheduled tasks.');

        // Send SIGTERM
        $process->signal(SIGTERM);

        // Wait for process to exit gracefully
        $exitCode = $process->wait();

        // Verify graceful shutdown
        $stdout = $process->getOutput();
        $this->assertStringContainsString('Handled signal: 15', $stdout);
        $this->assertSame(0, $exitCode);
    }
}
