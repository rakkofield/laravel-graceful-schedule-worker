<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Unit\Dispatcher;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatchResult;
use Symfony\Component\Process\Process;

class LocalDispatchResultTest extends TestCase
{
    /**
     * @testdox T2.20
     */
    public function testImplementsDispatchResultInterface(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $process->wait();
    }

    /**
     * @testdox T2.21
     */
    public function testSuccessCreatesStartedResult(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');

        $this->assertTrue($result->isStarted());
        $this->assertNull($result->getError());
        $process->wait();
    }

    /**
     * @testdox T2.22
     */
    public function testSuccessStoresProcess(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');

        $this->assertSame($process, $result->getProcess());
        $process->wait();
    }

    /**
     * @testdox T2.23
     */
    public function testFailedCreatesNotStartedResult(): void
    {
        $result = LocalDispatchResult::failed('test-id', 'php artisan test', 'Something went wrong');

        $this->assertFalse($result->isStarted());
        $this->assertNull($result->getProcess());
    }

    /**
     * @testdox T2.24
     */
    public function testFailedStoresErrorMessage(): void
    {
        $result = LocalDispatchResult::failed('test-id', 'php artisan test', 'Something went wrong');

        $this->assertSame('Something went wrong', $result->getError());
    }

    /**
     * @testdox T2.25
     */
    public function testGetDispatcherTypeReturnsLocal(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $successResult = LocalDispatchResult::success($process, 'test-id', 'php artisan test');
        $failedResult = LocalDispatchResult::failed('test-id', 'php artisan test', 'error');

        $this->assertSame('local', $successResult->getDispatcherType());
        $this->assertSame('local', $failedResult->getDispatcherType());
        $process->wait();
    }

    /**
     * @testdox T2.26
     */
    public function testGetEventIdentifierReturnsCorrectValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = LocalDispatchResult::success($process, 'my-event-identifier', 'php artisan test');

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
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan report:daily');

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
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');
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
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');

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
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');

        $this->assertFalse($result->isRunning());
    }

    /**
     * @testdox T2.31
     */
    public function testIsRunningReturnsFalseWhenProcessIsNull(): void
    {
        $result = LocalDispatchResult::failed('test-id', 'php artisan test', 'error');

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
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');

        $this->assertSame(0, $result->getExitCode());
    }

    /**
     * @testdox T2.33
     */
    public function testGetExitCodeReturnsNullWhenProcessIsNull(): void
    {
        $result = LocalDispatchResult::failed('test-id', 'php artisan test', 'error');

        $this->assertNull($result->getExitCode());
    }

    /**
     * @testdox T2.34
     */
    public function testGetExitCodeReturnsNullWhenProcessStillRunning(): void
    {
        $process = Process::fromShellCommandLine('sleep 2');
        $process->start();
        $result = LocalDispatchResult::success($process, 'test-id', 'php artisan test');

        $this->assertNull($result->getExitCode());
        $process->stop(0);
    }

    /**
     * @testdox T2.35
     */
    public function testFailedResultStoresEventIdentifierAndCommand(): void
    {
        $result = LocalDispatchResult::failed('failed-event-id', 'php artisan failed:command', 'error');

        $this->assertSame('failed-event-id', $result->getEventIdentifier());
        $this->assertSame('php artisan failed:command', $result->getEventCommand());
    }
}
