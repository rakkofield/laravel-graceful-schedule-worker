<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * @testdox SkippedDispatchResultFactory
 */
class SkippedDispatchResultFactoryTest extends TestCase
{
    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var DateTimeImmutable */
    private $factoryNow;

    /** @var FixedClock */
    private $clock;

    /** @var SkippedDispatchResultFactory */
    private $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatchedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $this->factoryNow = new DateTimeImmutable('2024-01-15 10:00:05');
        $this->clock = new FixedClock($this->factoryNow);
        $this->factory = new SkippedDispatchResultFactory($this->clock);
    }

    /**
     * @testdox SDF.1 create() returns a SkippedDispatchResultInterface
     */
    public function testCreateReturnsSkippedDispatchResultInterface(): void
    {
        $result = $this->factory->create(
            'id',
            'cmd',
            SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
            $this->dispatchedAt,
            DispatcherType::LOCAL
        );

        $this->assertInstanceOf(SkippedDispatchResultInterface::class, $result);
        $this->assertSame('id', $result->getEventIdentifier());
        $this->assertSame('cmd', $result->getEventCommand());
        $this->assertSame(
            SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
            $result->getReason()
        );
    }

    /**
     * @testdox SDF.2 create() propagates the supplied dispatcherType (local)
     */
    public function testCreatePropagatesLocalDispatcherType(): void
    {
        $result = $this->factory->create(
            'id',
            'cmd',
            SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
            $this->dispatchedAt,
            DispatcherType::LOCAL
        );

        $this->assertSame(DispatcherType::LOCAL, $result->getDispatcherType());
    }

    /**
     * @testdox SDF.2b create() propagates the supplied dispatcherType (stepfunctions)
     */
    public function testCreatePropagatesStepFunctionsDispatcherType(): void
    {
        $result = $this->factory->create(
            'id',
            'cmd',
            SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
            $this->dispatchedAt,
            DispatcherType::STEP_FUNCTIONS
        );

        $this->assertSame(DispatcherType::STEP_FUNCTIONS, $result->getDispatcherType());
    }

    /**
     * @testdox SDF.3 create() forwards the supplied dispatchedAt unchanged
     */
    public function testCreateForwardsDispatchedAt(): void
    {
        $result = $this->factory->create(
            'id',
            'cmd',
            SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
            $this->dispatchedAt,
            DispatcherType::LOCAL
        );

        $this->assertSame($this->dispatchedAt, $result->getDispatchedAt());
    }

    /**
     * @testdox SDF.4 create() stamps recordedAt from the injected clock
     */
    public function testCreateStampsRecordedAtFromInjectedClock(): void
    {
        $result = $this->factory->create(
            'id',
            'cmd',
            SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
            $this->dispatchedAt,
            DispatcherType::LOCAL
        );

        $this->assertSame($this->factoryNow, $result->getRecordedAt());
    }

    /**
     * @testdox SDF.5 dispatchedAt and recordedAt remain independent values
     */
    public function testDispatchedAtAndRecordedAtAreIndependent(): void
    {
        $result = $this->factory->create(
            'id',
            'cmd',
            SkippedDispatchResultInterface::REASON_LOCK_NOT_ACQUIRED,
            $this->dispatchedAt,
            DispatcherType::LOCAL
        );

        $this->assertNotSame($result->getDispatchedAt(), $result->getRecordedAt());
    }
}
