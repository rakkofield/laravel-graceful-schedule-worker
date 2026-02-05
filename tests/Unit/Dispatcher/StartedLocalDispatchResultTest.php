<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @testdox StartedLocalDispatchResult
 */
class StartedLocalDispatchResultTest extends TestCase
{
    /**
     * @testdox T2.20
     */
    public function testImplementsStartedDispatchResultInterface(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $process->wait();
    }

    /**
     * @testdox T2.22
     */
    public function testStoresProcess(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');

        $this->assertSame($process, $result->getProcess());
        $process->wait();
    }

    /**
     * @testdox T2.25
     */
    public function testGetDispatcherTypeReturnsLocal(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');

        $this->assertSame('local', $result->getDispatcherType());
        $process->wait();
    }

    /**
     * @testdox T2.26
     */
    public function testGetEventIdentifierReturnsCorrectValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'my-event-identifier', 'php artisan test');

        $this->assertSame('my-event-identifier', $result->getEventIdentifier());
        $process->wait();
    }

    /**
     * @testdox T2.27
     */
    public function testGetEventCommandReturnsCorrectValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan report:daily');

        $this->assertSame('php artisan report:daily', $result->getEventCommand());
        $process->wait();
    }

    /**
     * @testdox T2.28
     */
    public function testGetDispatchedAtReturnsDateTimeImmutable(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $beforeCreate = new DateTimeImmutable();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');
        $afterCreate = new DateTimeImmutable();

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $dispatchedAt);
        $this->assertGreaterThanOrEqual($beforeCreate, $dispatchedAt);
        $this->assertLessThanOrEqual($afterCreate, $dispatchedAt);
        $process->wait();
    }

    /**
     * @testdox T2.29
     */
    public function testIsRunningReturnsTrueWhenProcessIsRunning(): void
    {
        $process = Process::fromShellCommandLine('sleep 2');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');

        $this->assertTrue($result->isRunning());
        $process->stop(0);
    }

    /**
     * @testdox T2.30
     */
    public function testIsRunningReturnsFalseWhenProcessIsNotRunning(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $process->wait();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');

        $this->assertFalse($result->isRunning());
    }

    /**
     * @testdox T2.32
     */
    public function testGetExitCodeReturnsProcessExitCode(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $process->wait();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');

        $this->assertSame(0, $result->getExitCode());
    }

    /**
     * @testdox T2.34
     */
    public function testGetExitCodeReturnsNullWhenProcessStillRunning(): void
    {
        $process = Process::fromShellCommandLine('sleep 2');
        $process->start();
        $result = new StartedLocalDispatchResult($process, 'test-id', 'php artisan test');

        $this->assertNull($result->getExitCode());
        $process->stop(0);
    }

    /**
     * @testdox T2.40 dispatchedAt を明示的に渡した場合はその値が返される
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
}
