<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * E2E tests for GracefulScheduleWorkCommand using the skeleton application.
 *
 * These tests launch actual processes using the skeleton application.
 * They require PHP 8.4 or lower due to skeleton's Laravel 7.x dependency.
 *
 * LocalDispatcher uses buildCommand() for foreground and buildProcessCommand() for background.
 * beforeCallbacks are called synchronously in the parent process before dispatch.
 * afterCallbacks are called directly by LocalDispatcher (no schedule:finish subprocess).
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

            if (strpos($stdout, $expectedOutput) !== false) {
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
     * @testdox E2E.1 Graceful shutdown stops gracefully on SIGTERM
     */
    public function testGracefulShutdownStopsGracefullyOnSigterm(): void
    {
        $phpBinary = (new PhpExecutableFinder())->find(false);
        $process = new Process([$phpBinary, 'artisan', 'schedule:graceful-work'], self::SKELETON_PATH);

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
