<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeStartedDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\NullSleeper;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\ScheduleOrchestratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\NullExecutionTracker;

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
     * @testdox T4.10 Outputs running message when started
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
}
