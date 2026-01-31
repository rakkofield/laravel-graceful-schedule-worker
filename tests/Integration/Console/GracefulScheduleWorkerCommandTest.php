<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * E2E tests for GracefulScheduleWorkCommand using the skeleton application.
 *
 * These tests launch actual processes using the skeleton application.
 * They require PHP 8.4 or lower due to skeleton's Laravel 7.x dependency.
 *
 * Note: LocalDispatcher executes Event->command directly via Process::start(),
 * so Laravel Event callbacks (appendOutputTo, before, after, etc.) are NOT called.
 * This is by design - LocalDispatcher provides parallel execution of command strings.
 *
 * @group e2e
 */
final class GracefulScheduleWorkerCommandTest extends TestCase
{
    private const SKELETON_PATH = __DIR__ . '/../../../skeleton';
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
                $this->markTestSkipped('E2E tests require PHP 8.4 or lower (skeleton uses Laravel 7.x)');
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
