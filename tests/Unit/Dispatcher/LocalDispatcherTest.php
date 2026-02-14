<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\SkippedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FinishCommandTemplate;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\SpyCallbackEvent;

class LocalDispatcherTest extends TestCase
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @var FakeEventMutex
     */
    private $mutex;

    /**
     * @var DateTimeImmutable
     */
    private $dueAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
        $this->dueAt = new DateTimeImmutable('2024-01-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock);
    }

    private function createSpyEvent(string $command): SpyCallbackEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new SpyCallbackEvent($this->mutex, $command, $clock);
    }

    /**
     * @testdox LD.1 dispatchEvent returns StartedLocalDispatchResult
     */
    public function testReturnsLocalDispatchResult(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
    }

    /**
     * @testdox LD.2 Returns StartedDispatchResultInterface on success
     */
    public function testReturnsStartedDispatchResultInterfaceOnSuccess(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox LD.3 Returns correct eventIdentifier
     */
    public function testReturnsCorrectEventIdentifier(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertSame($event->mutexName(), $result->getEventIdentifier());
    }

    /**
     * @testdox LD.4 Returns correct eventCommand
     */
    public function testReturnsCorrectEventCommand(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('php artisan report:daily');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // buildCommand() returns the full command containing the original command
        $this->assertStringContainsString('php artisan report:daily', $result->getEventCommand());
    }

    /**
     * @testdox LD.5 dispatcherType returns 'local'
     */
    public function testReturnsCorrectDispatcherType(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox LD.6 Starts process in background
     */
    public function testStartsProcessInBackground(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('sleep 0.1');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertNotNull($result->getProcess());

        $result->getProcess()->wait();
    }

    /**
     * @testdox LD.7 Returns dispatchedAt timestamp
     */
    public function testReturnsDispatchedAtTimestamp(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $dispatchedAt = $result->getDispatchedAt();

        $this->assertEquals(new DateTimeImmutable('2024-01-15 10:00:00'), $dispatchedAt);
    }

    /**
     * @testdox LD.8 Process is running immediately after dispatch
     */
    public function testHasRunningProcessImmediatelyAfterDispatch(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('sleep 2');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertTrue($result->isRunning());

        $result->getProcess()->stop(0);
    }

    /**
     * @testdox LD.9 beforeCallbacks are called before dispatch
     */
    public function testBeforeCallbacksAreCalledBeforeDispatch(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createSpyEvent('echo test');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertTrue($event->wasBeforeCallbacksCalled());
    }

    /**
     * @testdox LD.10 background buildProcessCommand does not include schedule:finish (PHP-side finish)
     */
    public function testBuildProcessCommandDoesNotIncludeScheduleFinish(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // schedule:finish is NOT included (PHP-side finish handles it)
        $this->assertStringNotContainsString('schedule:finish', $result->getEventCommand());

        $result->getProcess()->wait();
    }

    /**
     * @testdox LD.11 runInBackground is preserved after dispatch
     */
    public function testRunInBackgroundIsPreservedAfterDispatch(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');
        $event->runInBackground = false;

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // runInBackground is not changed (respects Event's setting)
        $this->assertFalse($event->runInBackground);
    }

    /**
     * @testdox LD.12 output redirection is included in command
     */
    public function testOutputRedirectionIsIncludedInCommand(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');
        $event->sendOutputTo('/tmp/test-output.log');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // buildCommand() includes output redirection
        $this->assertStringContainsString('/tmp/test-output.log', $result->getEventCommand());
    }

    /**
     * @testdox LD.13 Returns failed result when beforeCallbacks throw an exception
     */
    public function testReturnsFailedWhenBeforeCallbackThrows(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createSpyEvent('echo test');
        $event->throwOnBeforeCallback(new \RuntimeException('Test exception'));

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertStringContainsString('RuntimeException', $result->getError());
        $this->assertStringContainsString('Test exception', $result->getError());
    }

    /**
     * @testdox LD.14 Error from beforeCallbacks is rethrown
     */
    public function testRethrowsErrorFromBeforeCallback(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createSpyEvent('echo test');
        $event->throwOnBeforeCallback(new \Error('Test error'));

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Test error');

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);
    }

    /**
     * @testdox LD.15 cleanup removes completed processes from internal list
     */
    public function testCleanupRemovesCompletedProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);

        // Dispatch a process that completes immediately (background)
        $event1 = $this->createEvent('echo test1');
        $event1->runInBackground = true;
        $result1 = $dispatcher->dispatchEvent($event1, $this->app, $this->dueAt);

        // Wait for the process to complete
        $result1->getProcess()->wait();

        // Dispatch a long-running process (background)
        $event2 = $this->createEvent('sleep 10');
        $event2->runInBackground = true;
        $result2 = $dispatcher->dispatchEvent($event2, $this->app, $this->dueAt);

        // Call cleanup
        $dispatcher->cleanup();

        // Call stopAll and verify remaining processes
        // The sleep process is still running so it will be stopped
        $this->assertTrue($result2->isRunning());

        // Cleanup
        $result2->getProcess()->stop(0);
    }

    /**
     * @testdox LD.16 stopAll stops all running processes
     */
    public function testStopAllStopsAllRunningProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.1);

        $proc1 = new StubProcess(true);
        $proc1->setTerminateOnSignal(true);
        $proc2 = new StubProcess(true);
        $proc2->setTerminateOnSignal(true);

        $dispatcher->addRunningProcess($this->createStubResult($proc1, 'event1'));
        $dispatcher->addRunningProcess($this->createStubResult($proc2, 'event2'));

        // Verify both are running
        $this->assertTrue($proc1->isRunning());
        $this->assertTrue($proc2->isRunning());

        // Call stopAll
        $dispatcher->stopAll();

        // Verify both are stopped
        $this->assertFalse($proc1->isRunning());
        $this->assertFalse($proc2->isRunning());
    }

    /**
     * @testdox LD.17 stopAll handles already stopped processes gracefully
     */
    public function testStopAllHandlesAlreadyStoppedProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);

        // Dispatch a process that completes immediately (background)
        $event = $this->createEvent('echo test');
        $event->runInBackground = true;
        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // Wait for the process to complete
        $result->getProcess()->wait();
        $this->assertFalse($result->isRunning());

        // Verify stopAll can be called without exception
        $dispatcher->stopAll();

        // Test passes = no exception
        $this->assertTrue(true);
    }

    /**
     * @testdox LD.18 dispatchEvent automatically adds result to running processes
     */
    public function testDispatchEventAddsResultToRunningProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);

        $event = $this->createEvent('sleep 5');
        $event->runInBackground = true;
        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // Indirectly verify it was added to the internal list by stopping via stopAll
        $dispatcher->stopAll();

        // Verify dispatching again works fine (internal list has been cleared)
        $event2 = $this->createEvent('echo test');
        $event2->runInBackground = true;
        $result = $dispatcher->dispatchEvent($event2, $this->app, $this->dueAt);
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);

        $result->getProcess()->wait();
    }

    private function createStubResult(StubProcess $process, string $identifier = 'test'): StartedLocalDispatchResult
    {
        return new StartedLocalDispatchResult($process, $identifier, 'echo stub', new DateTimeImmutable());
    }

    /**
     * @testdox LD.19 stopAll sends SIGTERM to all running processes
     */
    public function testStopAllSendsSignalToAllProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.1);

        $proc1 = new StubProcess(true);
        $proc1->setTerminateOnSignal(true);
        $proc2 = new StubProcess(true);
        $proc2->setTerminateOnSignal(true);

        $dispatcher->addRunningProcess($this->createStubResult($proc1, 'event1'));
        $dispatcher->addRunningProcess($this->createStubResult($proc2, 'event2'));

        $dispatcher->stopAll();

        $this->assertContains(SIGTERM, $proc1->getReceivedSignals());
        $this->assertContains(SIGTERM, $proc2->getReceivedSignals());
    }

    /**
     * @testdox LD.20 stopAll sends SIGKILL to processes that don't stop after SIGTERM
     */
    public function testStopAllSendsKillToProcessesThatDontStop(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.05);

        $proc = new StubProcess(true);
        // terminateOnSignal = false -> ignores SIGTERM
        $proc->setTerminateOnSignal(false);

        $dispatcher->addRunningProcess($this->createStubResult($proc, 'event1'));

        $dispatcher->stopAll();

        $this->assertContains(SIGTERM, $proc->getReceivedSignals());
        $this->assertContains(SIGKILL, $proc->getReceivedSignals());
    }

    /**
     * @testdox LD.21 stopAll handles signal exception gracefully
     */
    public function testStopAllHandlesSignalExceptionGracefully(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.05);

        $throwingProc = new StubProcess(true);
        $throwingProc->willThrowOnSignal(new \RuntimeException('Signal failed'));

        $normalProc = new StubProcess(true);
        $normalProc->setTerminateOnSignal(true);

        $dispatcher->addRunningProcess($this->createStubResult($throwingProc, 'throwing'));
        $dispatcher->addRunningProcess($this->createStubResult($normalProc, 'normal'));

        // Completes without exception
        $dispatcher->stopAll();

        // SIGTERM was sent to the normal process
        $this->assertContains(SIGTERM, $normalProc->getReceivedSignals());
    }

    /**
     * @testdox LD.22 stopAll skips signal for non-running processes
     */
    public function testStopAllSkipsSignalForNonRunningProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.05);

        $runningProc = new StubProcess(true);
        $runningProc->setTerminateOnSignal(true);

        $stoppedProc = new StubProcess(false); // Already stopped

        $dispatcher->addRunningProcess($this->createStubResult($runningProc, 'running'));
        $dispatcher->addRunningProcess($this->createStubResult($stoppedProc, 'stopped'));

        $dispatcher->stopAll();

        // SIGTERM is sent to the running process
        $this->assertContains(SIGTERM, $runningProc->getReceivedSignals());
        // No signal is sent to the already stopped process
        $this->assertEmpty($stoppedProc->getReceivedSignals());
    }

    /**
     * @testdox LD.23 foreground event runs synchronously with Process::run()
     */
    public function testForegroundEventRunsSynchronously(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo foreground');
        // runInBackground defaults to false

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // Synchronous execution, so the process has already finished by the time it returns
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertFalse($result->isRunning());
        $this->assertSame(0, $result->getExitCode());
    }

    /**
     * @testdox LD.24 foreground event calls afterCallbacksWithExitCode
     */
    public function testForegroundEventCallsAfterCallbacks(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createSpyEvent('echo test');
        // runInBackground defaults to false

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertTrue($event->wasAfterCallbacksWithExitCodeCalled());
        $this->assertSame(0, $event->getAfterCallbacksExitCode());
    }

    /**
     * @testdox LD.25 foreground event result is not added to running processes
     */
    public function testForegroundEventResultNotAddedToRunningProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');
        // runInBackground defaults to false

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // Verify stopAll has nothing to do (internal list is empty)
        // No exception after cleanup + stopAll = not added to tracking list
        $dispatcher->cleanup();
        $dispatcher->stopAll();

        $this->assertTrue(true);
    }

    /**
     * @testdox LD.26 background event runs asynchronously with Process::start()
     */
    public function testBackgroundEventRunsAsynchronously(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('sleep 2');
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // Asynchronous execution, so the process is still running when it returns
        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertTrue($result->isRunning());

        $result->getProcess()->stop(0);
    }

    /**
     * @testdox LD.27 ClockAwareEvent in background dispatch generates command via buildProcessCommand()
     */
    public function testClockAwareEventUseBuildProcessCommandInBackground(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'echo clockaware', $clock);
        $event->runInBackground = true;

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        // buildProcessCommand() uses exec prefix
        $this->assertStringStartsWith('exec ', $result->getEventCommand());
        // schedule:finish is NOT included (PHP-side finish handles it)
        $this->assertStringNotContainsString('schedule:finish', $result->getEventCommand());

        $result->getProcess()->wait();
    }

    /**
     * @testdox LD.28 Foreground non-zero exit code is passed correctly to afterCallbacks
     */
    public function testForegroundNonZeroExitCodePassedToAfterCallbacks(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createSpyEvent('exit 42');

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertTrue($event->wasAfterCallbacksWithExitCodeCalled());
        $this->assertSame(42, $event->getAfterCallbacksExitCode());
    }

    /**
     * @testdox LD.29 Foreground returns StartedLocalDispatchResult even when afterCallbacks throw an exception
     */
    public function testForegroundAfterCallbackExceptionReturnsStartedResult(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createSpyEvent('echo test');
        $event->throwOnAfterCallback(new \RuntimeException('afterCallback error'));

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertFalse($result->isRunning());
        $this->assertSame(0, $result->getExitCode());
    }

    /**
     * @testdox LD.30 withoutOverlapping returns SkippedDispatchResult when mutex already exists
     */
    public function testWithoutOverlappingReturnsSkippedWhenMutexAlreadyExists(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');
        $event->withoutOverlapping();

        // First call claims the mutex
        $this->mutex->create($event);

        // Second call should fail because mutex already exists
        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(SkippedDispatchResult::class, $result);
        $this->assertSame('withoutOverlapping', $result->getReason());
        $this->assertSame('local', $result->getDispatcherType());
    }

    /**
     * @testdox LD.31 withoutOverlapping calls mutex.create before dispatch
     */
    public function testWithoutOverlappingCallsMutexCreateBeforeDispatch(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');
        $event->withoutOverlapping();

        $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertSame(1, $this->mutex->getCreateCount($event->mutexName()));
    }

    /**
     * @testdox LD.32 withoutOverlapping proceeds normally when mutex.create succeeds
     */
    public function testWithoutOverlappingProceedsNormallyWhenMutexCreateSucceeds(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new LocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);
        $event = $this->createEvent('echo test');
        $event->withoutOverlapping();

        $result = $dispatcher->dispatchEvent($event, $this->app, $this->dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
    }

    /**
     * @testdox LD.33 cleanup runs finish command for completed processes
     */
    public function testCleanupRunsFinishCommandForCompletedProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);

        // Completed process with finishCommandTemplate
        $proc1 = new StubProcess(false);
        $result1 = new StartedLocalDispatchResult(
            $proc1,
            'event1',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event1"', '>> /dev/null 2>&1')
        );

        // Running process with finishCommandTemplate
        $proc2 = new StubProcess(true);
        $result2 = new StartedLocalDispatchResult(
            $proc2,
            'event2',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event2"', '>> /dev/null 2>&1')
        );

        $dispatcher->addRunningProcess($result1);
        $dispatcher->addRunningProcess($result2);

        $dispatcher->cleanup();

        // Finish command was run only for the completed process
        $this->assertCount(1, $dispatcher->getFinishCommandsRun());
        $this->assertStringContainsString('event1', $dispatcher->getFinishCommandsRun()[0]);
        $this->assertStringContainsString(' 143 ', $dispatcher->getFinishCommandsRun()[0]);
    }

    /**
     * @testdox LD.34 stopAll Phase 4 runs finish command for all processes
     */
    public function testStopAllRunsFinishCommandForAllProcesses(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.05);

        $proc1 = new StubProcess(true);
        $proc1->setTerminateOnSignal(true);
        $result1 = new StartedLocalDispatchResult(
            $proc1,
            'event1',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event1"', '>> /dev/null 2>&1')
        );

        $proc2 = new StubProcess(true);
        $proc2->setTerminateOnSignal(true);
        $result2 = new StartedLocalDispatchResult(
            $proc2,
            'event2',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event2"', '>> /dev/null 2>&1')
        );

        $dispatcher->addRunningProcess($result1);
        $dispatcher->addRunningProcess($result2);

        $dispatcher->stopAll();

        // Both processes got finish commands with exit code 143
        $this->assertCount(2, $dispatcher->getFinishCommandsRun());
        $this->assertStringContainsString(' 143 ', $dispatcher->getFinishCommandsRun()[0]);
        $this->assertStringContainsString(' 143 ', $dispatcher->getFinishCommandsRun()[1]);
    }

    /**
     * @testdox LD.35 cleanup: runFinishCommand exception does not prevent other finish commands from running
     */
    public function testRunFinishCommandExceptionDoesNotStopOtherFinishes(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock);

        $proc1 = new StubProcess(false);
        $result1 = new StartedLocalDispatchResult(
            $proc1,
            'event1',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event1"', '>> /dev/null 2>&1')
        );

        $proc2 = new StubProcess(false);
        $result2 = new StartedLocalDispatchResult(
            $proc2,
            'event2',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event2"', '>> /dev/null 2>&1')
        );

        $dispatcher->addRunningProcess($result1);
        $dispatcher->addRunningProcess($result2);

        // First finish command will throw
        $dispatcher->willThrowOnFinishCommand(0, new \RuntimeException('Finish failed'));

        $dispatcher->cleanup();

        // Both finish commands were attempted despite first one throwing
        $this->assertCount(2, $dispatcher->getFinishCommandsRun());
    }

    /**
     * @testdox LD.36 stopAll Phase 4: first finish command exception does not prevent second from running
     */
    public function testStopAllFinishCommandExceptionDoesNotStopOtherFinishes(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.05);

        $proc1 = new StubProcess(true);
        $proc1->setTerminateOnSignal(true);
        $result1 = new StartedLocalDispatchResult(
            $proc1,
            'event1',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event1"', '>> /dev/null 2>&1')
        );

        $proc2 = new StubProcess(true);
        $proc2->setTerminateOnSignal(true);
        $result2 = new StartedLocalDispatchResult(
            $proc2,
            'event2',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event2"', '>> /dev/null 2>&1')
        );

        $dispatcher->addRunningProcess($result1);
        $dispatcher->addRunningProcess($result2);

        // First finish command will throw
        $dispatcher->willThrowOnFinishCommand(0, new \RuntimeException('Finish failed'));

        $dispatcher->stopAll();

        // Both finish commands were attempted despite first one throwing
        $this->assertCount(2, $dispatcher->getFinishCommandsRun());
        $this->assertStringContainsString('event2', $dispatcher->getFinishCommandsRun()[1]);
    }

    /**
     * @testdox LD.37 finish command uses exit code 143 when process exit code is null
     */
    public function testFinishCommandUsesExitCode143WhenProcessExitCodeIsNull(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $dispatcher = new TestableLocalDispatcher(null, new NullLogger(), new NullSleeper(), $fixedClock, 0.05);

        // Process with null exit code (terminated but exit code not captured)
        $proc = new StubProcess(true);
        $proc->setTerminateOnSignal(true);
        $result = new StartedLocalDispatchResult(
            $proc,
            'event1',
            'echo test',
            new DateTimeImmutable(),
            new FinishCommandTemplate('schedule:finish "event1"', '>> /dev/null 2>&1')
        );

        $dispatcher->addRunningProcess($result);

        $dispatcher->stopAll();

        $this->assertCount(1, $dispatcher->getFinishCommandsRun());
        $this->assertStringContainsString(' 143 ', $dispatcher->getFinishCommandsRun()[0]);
    }
}
