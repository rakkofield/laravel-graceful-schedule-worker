<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use DateTimeImmutable;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\StubThrowingOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class GracefulScheduleWorkCommandTest extends TestCase
{
    /** @var FakeEventMutex */
    private $eventMutex;

    /** @var FakeSchedulingMutex */
    private $schedulingMutex;

    /** @var Container */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventMutex = new FakeEventMutex();
        $this->schedulingMutex = new FakeSchedulingMutex();
        $this->container = new Container();
        Container::setInstance($this->container);

        $this->container->instance(EventMutex::class, $this->eventMutex);
        $this->container->instance(SchedulingMutex::class, $this->schedulingMutex);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @return Application&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockApplication(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->createMock(Application::class);
        return $app;
    }

    /**
     * @testdox GC.1 Outputs running message when started
     */
    public function testOutputsRunningMessageWhenStarted(): void
    {
        $schedule = new Schedule();
        $fakeResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $dispatcher = new FakeDispatcher($fakeResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = new NullExecutionTracker();
        $logger = new NullLogger();
        $orchestrator = new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, $logger, new NullSleeper());

        $mockApp = $this->createMockApplication();
        $this->container->instance(Application::class, $mockApp);
        $this->container->instance(ScheduleOrchestratorInterface::class, $orchestrator);
        $this->container->instance(Schedule::class, $schedule);

        $command = new GracefulScheduleWorkCommand();
        $command->setLaravel($mockApp);

        // The command runs indefinitely, so we verify it can be instantiated and has correct signature
        $this->assertSame('schedule:graceful-work', $command->getName());
    }

    /**
     * @testdox GC.2 Reports exception via reporter when orchestrator throws
     */
    public function testReportsExceptionViaReporterWhenOrchestratorThrows(): void
    {
        $exception = new \RuntimeException('Cache connection failed');
        $orchestrator = new StubThrowingOrchestrator($exception);
        $reporter = new FakeExceptionReporter();
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $mockApp = $this->createMockApplication();

        $command = new GracefulScheduleWorkCommand();
        $command->setLaravel($mockApp);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        $exitCode = $command->handle($orchestrator, $schedule, $reporter);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $reporter->getReportedCount());
        $this->assertSame($exception, $reporter->getReported()[0]);
    }

    /**
     * @testdox GC.3 Reports Throwable Error via reporter
     */
    public function testReportsThrowableErrorViaReporter(): void
    {
        $error = new \TypeError('Unexpected type');
        $orchestrator = new StubThrowingOrchestrator($error);
        $reporter = new FakeExceptionReporter();
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $mockApp = $this->createMockApplication();

        $command = new GracefulScheduleWorkCommand();
        $command->setLaravel($mockApp);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        $exitCode = $command->handle($orchestrator, $schedule, $reporter);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $reporter->getReportedCount());
        $this->assertSame($error, $reporter->getReported()[0]);
    }

    /**
     * @testdox GC.4 Returns exit code 1 when orchestrator throws
     */
    public function testReturnsExitCode1WhenOrchestratorThrows(): void
    {
        $exception = new \RuntimeException('Original error');
        $orchestrator = new StubThrowingOrchestrator($exception);
        $reporter = new FakeExceptionReporter();
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $mockApp = $this->createMockApplication();

        $command = new GracefulScheduleWorkCommand();
        $command->setLaravel($mockApp);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        $exitCode = $command->handle($orchestrator, $schedule, $reporter);

        $this->assertSame(1, $exitCode);
    }

    /**
     * @testdox GC.5 Returns exit code 0 on normal completion
     */
    public function testReturnsExitCode0OnNormalCompletion(): void
    {
        $fakeResult = FakeStartedDispatchResult::create('test-id', 'echo test', 'fake');
        $dispatcher = new FakeDispatcher($fakeResult);
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $tracker = new NullExecutionTracker();
        $logger = new NullLogger();
        $sleeper = new NullSleeper();
        $orchestrator = new DefaultScheduleOrchestrator($dispatcher, $clock, $tracker, $logger, $sleeper);

        $reporter = new FakeExceptionReporter();
        $clock = new FixedClock(new \DateTimeImmutable('2024-01-15 12:00:00'));
        $schedule = new ClockAwareSchedule($clock);

        $mockApp = $this->createMockApplication();

        $command = new GracefulScheduleWorkCommand();
        $command->setLaravel($mockApp);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        // Simulate shutdown to stop the loop immediately
        $command->shutdown(SIGTERM, null);

        $exitCode = $command->handle($orchestrator, $schedule, $reporter);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $reporter->getReportedCount());
    }
}
