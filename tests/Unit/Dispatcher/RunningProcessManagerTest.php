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
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\SpyCallbackEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ThrowingOnForgetEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;

class RunningProcessManagerTest extends TestCase
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @var FakeEventMutex
     */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createManager(float $stopTimeout = 10.0): RunningProcessManager
    {
        return new RunningProcessManager($this->app, new NullLogger(), new NullSleeper(), $stopTimeout);
    }

    private function createSpyEvent(string $command): SpyCallbackEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new SpyCallbackEvent($this->mutex, $command, $clock);
    }

    private function createStubResult(
        StubProcess $process,
        string $identifier = 'test',
        ?SpyCallbackEvent $event = null
    ): StartedLocalDispatchResult {
        return new StartedLocalDispatchResult($process, $identifier, 'echo stub', new DateTimeImmutable(), $event);
    }

    /**
     * @testdox RPM.1 cleanup runs afterCallbacks for completed processes
     */
    public function testCleanupRunsAfterCallbacksForCompletedProcesses(): void
    {
        $manager = $this->createManager();

        $proc1 = new StubProcess(false);
        $event1 = $this->createSpyEvent('echo test');
        $manager->add($this->createStubResult($proc1, 'event1', $event1));

        $proc2 = new StubProcess(true);
        $event2 = $this->createSpyEvent('echo test');
        $manager->add($this->createStubResult($proc2, 'event2', $event2));

        $manager->cleanup();

        $this->assertTrue($event1->wasAfterCallbacksWithExitCodeCalled());
        $this->assertFalse($event2->wasAfterCallbacksWithExitCodeCalled());
    }

    /**
     * @testdox RPM.2 cleanup keeps running processes in tracking list
     */
    public function testCleanupKeepsRunningProcesses(): void
    {
        $manager = $this->createManager(0.05);

        $proc = new StubProcess(true);
        $proc->setTerminateOnSignal(true);
        $event = $this->createSpyEvent('echo test');
        $manager->add($this->createStubResult($proc, 'event1', $event));

        $manager->cleanup();

        // Process is still tracked; stopAll will find it
        $manager->stopAll();
        $this->assertContains(SIGTERM, $proc->getReceivedSignals());
    }

    /**
     * @testdox RPM.3 cleanup logs warning when afterCallback throws
     */
    public function testCleanupLogsWarningOnAfterCallbackException(): void
    {
        $logger = new SpyLogger();
        $manager = new RunningProcessManager($this->app, $logger, new NullSleeper(), 10.0);

        $proc = new StubProcess(false);
        $event = $this->createSpyEvent('echo test');
        $event->throwOnAfterCallback(new \RuntimeException('afterCallback failed'));
        $manager->add($this->createStubResult($proc, 'event1', $event));

        $manager->cleanup();

        $this->assertTrue($logger->hasLogContaining('warning', 'afterCallback failed during cleanup'));
    }

    /**
     * @testdox RPM.4 cleanup afterCallback exception does not prevent other callbacks
     */
    public function testCleanupContinuesAfterCallbackException(): void
    {
        $manager = $this->createManager();

        $proc1 = new StubProcess(false);
        $event1 = $this->createSpyEvent('echo test');
        $event1->throwOnAfterCallback(new \RuntimeException('afterCallback failed'));
        $manager->add($this->createStubResult($proc1, 'event1', $event1));

        $proc2 = new StubProcess(false);
        $event2 = $this->createSpyEvent('echo test');
        $manager->add($this->createStubResult($proc2, 'event2', $event2));

        $manager->cleanup();

        $this->assertTrue($event1->wasAfterCallbacksWithExitCodeCalled());
        $this->assertTrue($event2->wasAfterCallbacksWithExitCodeCalled());
    }

    /**
     * @testdox RPM.5 stopAll sends SIGTERM then SIGKILL to processes that ignore SIGTERM
     */
    public function testStopAllSendsSigtermThenSigkill(): void
    {
        $manager = $this->createManager(0.05);

        $proc = new StubProcess(true);
        // Ignores SIGTERM
        $proc->setTerminateOnSignal(false);
        $manager->add($this->createStubResult($proc, 'event1'));

        $manager->stopAll();

        $this->assertContains(SIGTERM, $proc->getReceivedSignals());
        $this->assertContains(SIGKILL, $proc->getReceivedSignals());
    }

    /**
     * @testdox RPM.6 stopAll does not send SIGKILL when SIGTERM succeeds
     */
    public function testStopAllSkipsSigkillWhenSigtermSucceeds(): void
    {
        $manager = $this->createManager(0.05);

        $proc = new StubProcess(true);
        $proc->setTerminateOnSignal(true);
        $manager->add($this->createStubResult($proc, 'event1'));

        $manager->stopAll();

        $this->assertContains(SIGTERM, $proc->getReceivedSignals());
        $this->assertNotContains(SIGKILL, $proc->getReceivedSignals());
    }

    /**
     * @testdox RPM.7 stopAll skips signal for already stopped processes
     */
    public function testStopAllSkipsSignalForStoppedProcesses(): void
    {
        $manager = $this->createManager(0.05);

        $stoppedProc = new StubProcess(false);
        $manager->add($this->createStubResult($stoppedProc, 'stopped'));

        $manager->stopAll();

        $this->assertEmpty($stoppedProc->getReceivedSignals());
    }

    /**
     * @testdox RPM.8 stopAll handles signal exception gracefully
     */
    public function testStopAllHandlesSignalExceptionGracefully(): void
    {
        $manager = $this->createManager(0.05);

        $throwingProc = new StubProcess(true);
        $throwingProc->willThrowOnSignal(new \RuntimeException('Signal failed'));

        $normalProc = new StubProcess(true);
        $normalProc->setTerminateOnSignal(true);

        $manager->add($this->createStubResult($throwingProc, 'throwing'));
        $manager->add($this->createStubResult($normalProc, 'normal'));

        $manager->stopAll();

        $this->assertContains(SIGTERM, $normalProc->getReceivedSignals());
    }

    /**
     * @testdox RPM.9 stopAll releases withoutOverlapping mutexes
     */
    public function testStopAllReleasesWithoutOverlappingMutexes(): void
    {
        $manager = $this->createManager(0.05);

        $proc = new StubProcess(true);
        $proc->setTerminateOnSignal(true);
        $event = $this->createSpyEvent('echo test');
        $event->withoutOverlapping();
        $this->mutex->create($event);
        $manager->add($this->createStubResult($proc, 'event1', $event));

        $manager->stopAll();

        $this->assertSame(1, $this->mutex->getForgetCount($event->mutexName()));
        $this->assertFalse($this->mutex->exists($event));
    }

    /**
     * @testdox RPM.10 stopAll does not release mutex for events without withoutOverlapping
     */
    public function testStopAllSkipsMutexReleaseForNonOverlappingEvents(): void
    {
        $manager = $this->createManager(0.05);

        $proc = new StubProcess(true);
        $proc->setTerminateOnSignal(true);
        $event = $this->createSpyEvent('echo test');
        // withoutOverlapping is NOT set
        $manager->add($this->createStubResult($proc, 'event1', $event));

        $manager->stopAll();

        $this->assertSame(0, $this->mutex->getForgetCount($event->mutexName()));
    }

    /**
     * @testdox RPM.11 stopAll does not run afterCallbacks
     */
    public function testStopAllDoesNotRunAfterCallbacks(): void
    {
        $manager = $this->createManager(0.05);

        $proc = new StubProcess(true);
        $proc->setTerminateOnSignal(true);
        $event = $this->createSpyEvent('echo test');
        $manager->add($this->createStubResult($proc, 'event1', $event));

        $manager->stopAll();

        $this->assertFalse($event->wasAfterCallbacksWithExitCodeCalled());
    }

    /**
     * @testdox RPM.12 stopAll mutex release failure does not prevent other releases
     */
    public function testStopAllMutexReleaseFailureContinues(): void
    {
        $fixedClock = new FixedClock(new DateTimeImmutable('2024-01-15 10:00:00'));
        $manager = $this->createManager(0.05);

        // event1: mutex that throws on forget
        $throwingMutex = new ThrowingOnForgetEventMutex(new \RuntimeException('mutex forget failed'));
        $proc1 = new StubProcess(true);
        $proc1->setTerminateOnSignal(true);
        $event1 = new SpyCallbackEvent($throwingMutex, 'echo test1', $fixedClock);
        $event1->withoutOverlapping();
        $throwingMutex->create($event1);
        $result1 = new StartedLocalDispatchResult($proc1, 'event1', 'echo test1', new DateTimeImmutable(), $event1);
        $manager->add($result1);

        // event2: normal mutex
        $proc2 = new StubProcess(true);
        $proc2->setTerminateOnSignal(true);
        $event2 = $this->createSpyEvent('echo test2');
        $event2->withoutOverlapping();
        $this->mutex->create($event2);
        $result2 = new StartedLocalDispatchResult($proc2, 'event2', 'echo test2', new DateTimeImmutable(), $event2);
        $manager->add($result2);

        $manager->stopAll();

        $this->assertSame(1, $throwingMutex->getForgetCount($event1->mutexName()));
        $this->assertSame(1, $this->mutex->getForgetCount($event2->mutexName()));
        $this->assertFalse($this->mutex->exists($event2));
    }

    /**
     * @testdox RPM.13 stopAll clears the tracking list
     */
    public function testStopAllClearsTrackingList(): void
    {
        $manager = $this->createManager(0.05);

        $proc = new StubProcess(true);
        $proc->setTerminateOnSignal(true);
        $manager->add($this->createStubResult($proc, 'event1'));

        $manager->stopAll();

        // Calling stopAll again should be a no-op (no signals sent)
        $signalsBefore = $proc->getReceivedSignals();
        $manager->stopAll();
        $this->assertSame($signalsBefore, $proc->getReceivedSignals());
    }

    /**
     * @testdox RPM.14 add tracks the result for subsequent cleanup
     */
    public function testAddTracksResultForCleanup(): void
    {
        $manager = $this->createManager();

        $proc = new StubProcess(false);
        $event = $this->createSpyEvent('echo test');
        $manager->add($this->createStubResult($proc, 'event1', $event));

        $manager->cleanup();

        $this->assertTrue($event->wasAfterCallbacksWithExitCodeCalled());
    }
}
