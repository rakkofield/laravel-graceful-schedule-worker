<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FinishCommandTemplate;
use Symfony\Component\Process\Process;

/**
 * @testdox StartedLocalDispatchResult
 */
class StartedLocalDispatchResultTest extends TestCase
{
    /**
     * @testdox SLR.1 Implements StartedDispatchResultInterface
     */
    public function testImplementsStartedDispatchResultInterface(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $process->wait();
    }

    /**
     * @testdox SLR.2 Stores Process
     */
    public function testStoresProcess(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertSame($process, $result->getProcess());
        $process->wait();
    }

    /**
     * @testdox SLR.3 getDispatcherType returns 'local'
     */
    public function testGetDispatcherTypeReturnsLocal(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertSame('local', $result->getDispatcherType());
        $process->wait();
    }

    /**
     * @testdox SLR.4 Returns correct eventIdentifier
     */
    public function testGetEventIdentifierReturnsCorrectValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $now = new DateTimeImmutable();
        $result = new StartedLocalDispatchResult($process, 'my-event-identifier', 'php artisan test', $now);

        $this->assertSame('my-event-identifier', $result->getEventIdentifier());
        $process->wait();
    }

    /**
     * @testdox SLR.5 Returns correct eventCommand
     */
    public function testGetEventCommandReturnsCorrectValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $now = new DateTimeImmutable();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan report:daily', $now);

        $this->assertSame('php artisan report:daily', $result->getEventCommand());
        $process->wait();
    }

    /**
     * @testdox SLR.6 getDispatchedAt returns DateTimeImmutable
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $now = new DateTimeImmutable();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', $now);

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $dispatchedAt);
        $this->assertSame($now, $dispatchedAt);
        $process->wait();
    }

    /**
     * @testdox SLR.7 isRunning returns true when process is running
     */
    public function testIsRunningReturnsTrueWhenProcessIsRunning(): void
    {
        $process = Process::fromShellCommandLine('sleep 2');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertTrue($result->isRunning());
        $process->stop(0);
    }

    /**
     * @testdox SLR.8 isRunning returns false after process stops
     */
    public function testIsRunningReturnsFalseWhenProcessIsNotRunning(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $process->wait();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertFalse($result->isRunning());
    }

    /**
     * @testdox SLR.9 Returns process exit code
     */
    public function testGetExitCodeReturnsProcessExitCode(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $process->wait();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertSame(0, $result->getExitCode());
    }

    /**
     * @testdox SLR.10 Returns null exit code when process is still running
     */
    public function testGetExitCodeReturnsNullWhenProcessStillRunning(): void
    {
        $process = Process::fromShellCommandLine('sleep 2');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertNull($result->getExitCode());
        $process->stop(0);
    }

    /**
     * @testdox SLR.11 Returns explicitly provided dispatchedAt value
     */
    public function testGetDispatchedAtReturnsExplicitValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $explicitTime = new DateTimeImmutable('2024-01-15 12:00:00');
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', $explicitTime);

        $this->assertSame($explicitTime, $result->getDispatchedAt());
        $process->wait();
    }

    /**
     * @testdox SLR.12 getFinishCommandTemplate returns null by default
     */
    public function testGetFinishCommandTemplateReturnsNullByDefault(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test', new DateTimeImmutable());

        $this->assertNull($result->getFinishCommandTemplate());
        $process->wait();
    }

    /**
     * @testdox SLR.13 getFinishCommandTemplate returns FinishCommandTemplate passed to constructor
     */
    public function testGetFinishCommandTemplateReturnsConstructorValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $template = new FinishCommandTemplate(
            'schedule:finish "test-mutex"',
            '>> /dev/null 2>&1'
        );
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            $template
        );

        $this->assertSame($template, $result->getFinishCommandTemplate());
        $process->wait();
    }
}
