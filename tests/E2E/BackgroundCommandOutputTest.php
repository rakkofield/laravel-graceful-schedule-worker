<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use DateTimeImmutable;
use Illuminate\Console\Application;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use Symfony\Component\Process\Process;

/**
 * E2E tests for background command output behavior using the skeleton application.
 *
 * Verifies that ProcessCommandBuilder generates correct shell commands
 * by executing them in the skeleton environment and checking actual file output.
 *
 * @group e2e
 */
final class BackgroundCommandOutputTest extends TestCase
{
    private const SKELETON_PATH = __DIR__ . '/../../skeleton';
    private const OUTPUT_DIR = __DIR__ . '/../tmp';

    protected function tearDown(): void
    {
        array_map('unlink', glob(self::OUTPUT_DIR . '/e2e_bg_*.log') ?: []);
        parent::tearDown();
    }

    /**
     * @testdox E2E.2 Background task output is preserved in file specified by sendOutputTo
     */
    public function testBackgroundTaskOutputIsPreservedWithSendOutputTo(): void
    {
        $outputFile = self::OUTPUT_DIR . '/e2e_bg_output.log';

        // Arrange: create ClockAwareEvent with runInBackground + sendOutputTo
        $event = new ClockAwareEvent(
            new FakeEventMutex(),
            Application::formatCommandString('hello'),
            new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00')),
            'local'
        );
        $event->runInBackground = true;
        $event->sendOutputTo($outputFile);

        // Act: build process command and execute in skeleton directory
        $command = $event->buildProcessCommand();
        $process = Process::fromShellCommandline($command, self::SKELETON_PATH);
        $process->setTimeout(10);
        $process->run();

        // Assert: main command output is preserved in the file
        $contents = file_get_contents($outputFile);
        $this->assertStringContainsString('Hello World!', $contents);
    }

    /**
     * @testdox E2E.3 SIGTERM is delivered directly to child process via exec
     */
    public function testSigtermIsDeliveredToChildProcess(): void
    {
        $outputFile = self::OUTPUT_DIR . '/e2e_bg_sigterm.log';

        // Use a PHP one-liner that writes STARTED then sleeps for a long time.
        // With exec, SIGTERM is delivered directly to the child process.
        $event = new ClockAwareEvent(
            new FakeEventMutex(),
            'php -r \'echo "STARTED\n"; sleep(60);\'',
            new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00')),
            'local'
        );
        $event->runInBackground = true;
        $event->sendOutputTo($outputFile);

        $command = $event->buildProcessCommand();
        $process = Process::fromShellCommandline($command, self::SKELETON_PATH);
        $process->setTimeout(10);
        $process->start();

        // Wait for the child to start
        $deadline = time() + 5;
        while (time() < $deadline) {
            if (
                file_exists($outputFile)
                && strpos((string) file_get_contents($outputFile), 'STARTED') !== false
            ) {
                break;
            }
            usleep(100000);
        }

        $this->assertFileExists($outputFile);
        $this->assertStringContainsString('STARTED', (string) file_get_contents($outputFile));

        // Send SIGTERM — exec ensures it goes directly to the child process
        $process->signal(SIGTERM);

        // Process should terminate promptly (not hang for 60 seconds)
        $process->wait();

        $this->assertFalse($process->isRunning());
    }
}
