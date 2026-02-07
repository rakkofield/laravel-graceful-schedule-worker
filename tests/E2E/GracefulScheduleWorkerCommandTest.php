<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use Illuminate\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * E2E tests for GracefulScheduleWorkCommand using the skeleton application.
 *
 * These tests launch actual processes using the skeleton application.
 * They require PHP 8.4 or lower due to skeleton's Laravel 7.x dependency.
 *
 * LocalDispatcher now uses buildCommand() to construct the full command including:
 * - Output redirection (appendOutputTo, sendOutputTo)
 * - schedule:finish call (which triggers afterCallbacks via Artisan command)
 * beforeCallbacks are called synchronously in the parent process before dispatch.
 *
 * @group e2e
 */
final class GracefulScheduleWorkerCommandTest extends TestCase
{
    private const SKELETON_PATH = __DIR__ . '/../../skeleton';
    private const MAX_WAIT_SECONDS = 10;
    private const POLL_INTERVAL_MICROSECONDS = 100000; // 100ms

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
     * @testdox T4.1 Graceful shutdown stops gracefully on SIGTERM
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
