<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcess;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\SpyCallbackEvent;
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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'my-event-identifier',
            'php artisan test',
            $now,
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan report:daily',
            $now,
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            $now,
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame(0, $result->getExitCode());
    }

    /**
     * @testdox SLR.10 Returns null exit code when process is still running
     */
    public function testGetExitCodeReturnsNullWhenProcessStillRunning(): void
    {
        $process = Process::fromShellCommandLine('sleep 2');
        $process->start();
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

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
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            $explicitTime,
            new DateTimeImmutable()
        );

        $this->assertSame($explicitTime, $result->getDispatchedAt());
        $process->wait();
    }

    /**
     * @testdox SLR.12 getEvent returns null by default
     */
    public function testGetEventReturnsNullByDefault(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertNull($result->getEvent());
        $process->wait();
    }

    /**
     * @testdox SLR.13 getEvent returns ClockAwareEvent passed to constructor
     */
    public function testGetEventReturnsConstructorValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $mutex = new FakeEventMutex();
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new SpyCallbackEvent($mutex, 'php artisan test', $clock);
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            $event
        );

        $this->assertSame($event, $result->getEvent());
        $process->wait();
    }

    /**
     * @testdox SLR.14 runAfterCallbacks calls callAfterCallbacksWithExitCode with process exit code
     */
    public function testRunAfterCallbacksCallsAfterCallbacksWithExitCode(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->run();
        $mutex = new FakeEventMutex();
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new SpyCallbackEvent($mutex, 'php artisan test', $clock);
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            $event
        );

        $container = new Container();
        $result->runAfterCallbacks($container);

        $this->assertTrue($event->wasAfterCallbacksWithExitCodeCalled());
        $this->assertSame(0, $event->getAfterCallbacksExitCode());
    }

    /**
     * @testdox SLR.15 runAfterCallbacks uses EXIT_CODE_SIGTERM when process exit code is null
     */
    public function testRunAfterCallbacksUsesExitCodeSigtermWhenNull(): void
    {
        // Use StubProcess to guarantee null exit code regardless of Symfony Process version
        $process = new StubProcess(false);
        $mutex = new FakeEventMutex();
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new SpyCallbackEvent($mutex, 'php artisan test', $clock);
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            $event
        );

        $this->assertNull($process->getExitCode());

        $container = new Container();
        $result->runAfterCallbacks($container);

        $this->assertTrue($event->wasAfterCallbacksWithExitCodeCalled());
        $this->assertSame(LocalDispatcher::EXIT_CODE_SIGTERM, $event->getAfterCallbacksExitCode());
    }

    /**
     * @testdox SLR.17 getRecordedAt returns constructor value
     */
    public function testGetRecordedAtReturnsConstructorValue(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $recordedAt = new DateTimeImmutable('2024-01-15 12:00:01');
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable('2024-01-15 12:00:00'),
            $recordedAt
        );

        $this->assertSame($recordedAt, $result->getRecordedAt());
        $process->wait();
    }

    /**
     * @testdox SLR.16 runAfterCallbacks is a no-op when event is null
     */
    public function testRunAfterCallbacksIsNoOpWhenEventIsNull(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->run();
        $result = new StartedLocalDispatchResult(
            $process,
            'test-id',
            'php artisan test',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $container = new Container();
        $result->runAfterCallbacks($container);

        // No exception thrown = success
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }
}
