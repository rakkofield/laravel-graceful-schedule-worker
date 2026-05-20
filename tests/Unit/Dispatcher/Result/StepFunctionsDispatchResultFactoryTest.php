<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * @testdox StepFunctionsDispatchResultFactory
 */
class StepFunctionsDispatchResultFactoryTest extends TestCase
{
    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var DateTimeImmutable */
    private $factoryNow;

    /** @var FixedClock */
    private $clock;

    /** @var StepFunctionsDispatchResultFactory */
    private $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $this->factoryNow = new DateTimeImmutable('2024-01-15 10:00:05');
        $this->clock = new FixedClock($this->factoryNow);
        $this->factory = new StepFunctionsDispatchResultFactory($this->clock);
    }

    /**
     * @testdox SRF.1 started() returns StartedStepFunctionsDispatchResult tagged with the stepfunctions dispatcher type
     */
    public function testStartedReturnsStepFunctionsStartedResult(): void
    {
        $result = $this->factory->started('arn', 'name', 'id', 'cmd', $this->dispatchedAt);

        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result);
        $this->assertSame(DispatcherType::STEP_FUNCTIONS, $result->getDispatcherType());
        $this->assertSame('arn', $result->getExecutionArn());
        $this->assertSame('name', $result->getExecutionName());
    }

    /**
     * @testdox SRF.2 started() forwards the supplied dispatchedAt and stamps recordedAt from the factory clock
     */
    public function testStartedTimestampsAreSourcedCorrectly(): void
    {
        $result = $this->factory->started('arn', 'name', 'id', 'cmd', $this->dispatchedAt);

        $this->assertSame($this->dispatchedAt, $result->getDispatchedAt());
        $this->assertSame($this->factoryNow, $result->getRecordedAt());
    }

    /**
     * @testdox SRF.3 alreadyRunning() returns AlreadyRunningStepFunctionsDispatchResult
     */
    public function testAlreadyRunningReturnsCorrectResult(): void
    {
        $result = $this->factory->alreadyRunning('name', 'id', 'cmd', $this->dispatchedAt);

        $this->assertInstanceOf(AlreadyRunningStepFunctionsDispatchResult::class, $result);
        $this->assertSame(DispatcherType::STEP_FUNCTIONS, $result->getDispatcherType());
        $this->assertSame('name', $result->getExecutionName());
    }

    /**
     * @testdox SRF.4 alreadyRunning() forwards dispatchedAt and stamps recordedAt
     */
    public function testAlreadyRunningTimestampsAreSourcedCorrectly(): void
    {
        $result = $this->factory->alreadyRunning('name', 'id', 'cmd', $this->dispatchedAt);

        $this->assertSame($this->dispatchedAt, $result->getDispatchedAt());
        $this->assertSame($this->factoryNow, $result->getRecordedAt());
    }

    /**
     * @testdox SRF.5 failed() returns FailedStepFunctionsDispatchResult and propagates the exception
     */
    public function testFailedReturnsStepFunctionsFailedResult(): void
    {
        $exception = new \RuntimeException('boom');

        $result = $this->factory->failed('name', 'id', 'cmd', $exception, $this->dispatchedAt);

        $this->assertInstanceOf(FailedStepFunctionsDispatchResult::class, $result);
        $this->assertSame(DispatcherType::STEP_FUNCTIONS, $result->getDispatcherType());
        $this->assertSame($exception, $result->getException());
        $this->assertStringContainsString('boom', $result->getError());
        $this->assertSame('name', $result->getExecutionName());
    }

    /**
     * @testdox SRF.6 failed() forwards dispatchedAt and stamps recordedAt from the factory clock
     */
    public function testFailedTimestampsAreSourcedCorrectly(): void
    {
        $exception = new \RuntimeException('boom');

        $result = $this->factory->failed('name', 'id', 'cmd', $exception, $this->dispatchedAt);

        $this->assertSame($this->dispatchedAt, $result->getDispatchedAt());
        $this->assertSame($this->factoryNow, $result->getRecordedAt());
    }

    /**
     * @testdox SRF.7 dispatchedAt and recordedAt remain independent values
     */
    public function testDispatchedAtAndRecordedAtAreIndependent(): void
    {
        $result = $this->factory->started('arn', 'name', 'id', 'cmd', $this->dispatchedAt);

        $this->assertNotSame($result->getDispatchedAt(), $result->getRecordedAt());
    }
}
