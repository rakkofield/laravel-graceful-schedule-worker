<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * @testdox StartExecutionInputFactory
 */
class StartExecutionInputFactoryTest extends TestCase
{
    /** @var Container */
    private $app;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var StartExecutionInputFactory */
    private $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->mutex = new FakeEventMutex();
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });

        $sanitizer = new MutexNameSanitizer();
        $this->factory = new StartExecutionInputFactory(
            new ExecutionNameGenerator($sanitizer),
            new PayloadBuilder(new LockKeyGenerator($sanitizer)),
            3600
        );
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock, 'local', null, new TimezoneResolver());
    }

    /**
     * @testdox SEIF.1 create() returns StartExecutionInput with correct executionName
     */
    public function testCreateReturnsInputWithCorrectExecutionName(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $input = $this->factory->create($event, $dueAt);

        $this->assertInstanceOf(StartExecutionInput::class, $input);
        $this->assertStringContainsString('1705282200', $input->getName());
    }

    /**
     * @testdox SEIF.2 create() returns StartExecutionInput with correct JSON payload
     */
    public function testCreateReturnsInputWithCorrectPayload(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $input = $this->factory->create($event, $dueAt);

        $decoded = json_decode($input->getInput(), true);
        $this->assertSame('php artisan report:daily', $decoded['command']);
        $this->assertSame($event->mutexName(), $decoded['mutexName']);
        $this->assertSame('2024-01-15T10:30:00+09:00', $decoded['dueAt']);
        $this->assertIsString($decoded['lockKey']);
        $this->assertSame($dueAt->getTimestamp() + 3600, $decoded['ttl']);
    }

    /**
     * @testdox SEIF.3 create() propagates StepFunctionsException on JSON encode failure
     */
    public function testCreatePropagatesStepFunctionsExceptionOnJsonFailure(): void
    {
        $event = $this->createEvent("\xFF\xFE");
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $this->expectException(StepFunctionsException::class);
        $this->expectExceptionMessage('Failed to encode input JSON');

        $this->factory->create($event, $dueAt);
    }
}
