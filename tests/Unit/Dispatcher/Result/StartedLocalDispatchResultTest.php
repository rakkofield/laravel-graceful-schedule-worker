<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @testdox StartedLocalDispatchResult
 */
class StartedLocalDispatchResultTest extends TestCase
{
    /**
     * @testdox SLR.1 StartedDispatchResultInterface を実装する
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
     * @testdox SLR.2 Process を保持する
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
     * @testdox SLR.3 getDispatcherType が 'local' を返す
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
     * @testdox SLR.4 正しい eventIdentifier を返す
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
     * @testdox SLR.5 正しい eventCommand を返す
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
     * @testdox SLR.6 getDispatchedAt が DateTimeImmutable を返す
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
     * @testdox SLR.7 プロセス実行中は isRunning が true を返す
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
     * @testdox SLR.8 プロセス停止後は isRunning が false を返す
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
     * @testdox SLR.9 プロセスの exit code を返す
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
     * @testdox SLR.10 実行中のプロセスの exit code は null を返す
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
     * @testdox SLR.11 dispatchedAt を明示的に渡した場合はその値が返される
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
