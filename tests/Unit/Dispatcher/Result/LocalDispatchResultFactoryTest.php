<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;
use Symfony\Component\Process\Process;

/**
 * @testdox LocalDispatchResultFactory
 */
class LocalDispatchResultFactoryTest extends TestCase
{
    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var DateTimeImmutable */
    private $factoryNow;

    /** @var FixedClock */
    private $clock;

    /** @var LocalDispatchResultFactory */
    private $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $this->factoryNow = new DateTimeImmutable('2024-01-15 10:00:05');
        $this->clock = new FixedClock($this->factoryNow);
        $this->factory = new LocalDispatchResultFactory(
            $this->clock,
            new SkippedDispatchResultFactory($this->clock)
        );
    }

    /**
     * @testdox LRF.1 skipped() delegates to SkippedDispatchResultFactory and tags the result as local
     */
    public function testSkippedReturnsLocalSkippedResult(): void
    {
        $result = $this->factory->skipped('id', 'cmd', 'lock_not_acquired', $this->dispatchedAt);

        $this->assertInstanceOf(SkippedDispatchResult::class, $result);
        $this->assertSame(DispatcherType::LOCAL, $result->getDispatcherType());
        $this->assertSame('lock_not_acquired', $result->getReason());
        $this->assertSame('id', $result->getEventIdentifier());
        $this->assertSame('cmd', $result->getEventCommand());
    }

    /**
     * @testdox LRF.2 skipped() forwards dispatchedAt and stamps recordedAt from the inner factory's clock
     */
    public function testSkippedTimestampsAreSourcedCorrectly(): void
    {
        $result = $this->factory->skipped('id', 'cmd', 'lock_not_acquired', $this->dispatchedAt);

        $this->assertSame($this->dispatchedAt, $result->getDispatchedAt());
        $this->assertSame($this->factoryNow, $result->getRecordedAt());
    }

    /**
     * @testdox LRF.3 started() returns StartedLocalDispatchResult tagged with the local dispatcher type
     */
    public function testStartedReturnsLocalStartedResult(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();

        $result = $this->factory->started($process, 'id', 'cmd', $this->dispatchedAt);

        $this->assertInstanceOf(StartedLocalDispatchResult::class, $result);
        $this->assertSame(DispatcherType::LOCAL, $result->getDispatcherType());
        $this->assertSame($process, $result->getProcess());
        $this->assertNull($result->getEvent());

        $process->wait();
    }

    /**
     * @testdox LRF.4 started() forwards dispatchedAt, optional event, and stamps recordedAt
     */
    public function testStartedPropagatesEventAndTimestamps(): void
    {
        $process = Process::fromShellCommandLine('echo test');
        $process->start();
        $event = new ClockAwareEvent(
            new FakeEventMutex(),
            'echo test',
            $this->clock,
            'local',
            null,
            new TimezoneResolver()
        );

        $result = $this->factory->started($process, 'id', 'cmd', $this->dispatchedAt, $event);

        $this->assertSame($event, $result->getEvent());
        $this->assertSame($this->dispatchedAt, $result->getDispatchedAt());
        $this->assertSame($this->factoryNow, $result->getRecordedAt());

        $process->wait();
    }

    /**
     * @testdox LRF.5 failed() returns FailedLocalDispatchResult and propagates the exception
     */
    public function testFailedReturnsLocalFailedResult(): void
    {
        $exception = new \RuntimeException('boom');

        $result = $this->factory->failed('id', 'cmd', $exception, $this->dispatchedAt);

        $this->assertInstanceOf(FailedLocalDispatchResult::class, $result);
        $this->assertSame(DispatcherType::LOCAL, $result->getDispatcherType());
        $this->assertSame($exception, $result->getException());
        $this->assertStringContainsString('boom', $result->getError());
    }

    /**
     * @testdox LRF.6 failed() forwards dispatchedAt and stamps recordedAt from the factory clock
     */
    public function testFailedTimestampsAreSourcedCorrectly(): void
    {
        $exception = new \RuntimeException('boom');

        $result = $this->factory->failed('id', 'cmd', $exception, $this->dispatchedAt);

        $this->assertSame($this->dispatchedAt, $result->getDispatchedAt());
        $this->assertSame($this->factoryNow, $result->getRecordedAt());
    }

    /**
     * @testdox LRF.7 dispatchedAt and recordedAt remain independent values
     */
    public function testDispatchedAtAndRecordedAtAreIndependent(): void
    {
        $result = $this->factory->skipped('id', 'cmd', 'lock_not_acquired', $this->dispatchedAt);

        $this->assertNotSame($result->getDispatchedAt(), $result->getRecordedAt());
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }
}
