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
 * @testdox PayloadBuilder
 */
class PayloadBuilderTest extends TestCase
{
    /** @var PayloadBuilder */
    private $builder;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var Container */
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        $sanitizer = new MutexNameSanitizer();
        $this->builder = new PayloadBuilder(new LockKeyGenerator($sanitizer));
        $this->mutex = new FakeEventMutex();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->bind(EventMutex::class, function () {
            return $this->mutex;
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param string $command
     * @return ClockAwareEvent
     */
    private function createEvent(string $command): ClockAwareEvent
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        return new ClockAwareEvent($this->mutex, $command, $clock, 'local', null, new TimezoneResolver());
    }

    /**
     * @testdox PB.1 build returns PayloadInterface with correct command
     */
    public function testBuildReturnsPayloadWithCorrectCommand(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);

        $this->assertInstanceOf(PayloadInterface::class, $payload);
        $this->assertSame('php artisan report:daily', $payload->getCommand());
    }

    /**
     * @testdox PB.2 build returns Payload with correct mutexName
     */
    public function testBuildReturnsPayloadWithCorrectMutexName(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);

        $this->assertSame($event->mutexName(), $payload->getMutexName());
    }

    /**
     * @testdox PB.3 build returns Payload with correct dueAt in ATOM format
     */
    public function testBuildReturnsPayloadWithCorrectDueAt(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);

        $this->assertSame('2024-01-15T10:30:00+09:00', $payload->getDueAt());
    }

    /**
     * @testdox PB.4 build returns Payload with expiresAt = dueAt timestamp + lockTtlSeconds
     */
    public function testBuildReturnsPayloadWithCorrectExpiresAt(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);

        $expectedExpiresAt = $dueAt->getTimestamp() + 3600;
        $this->assertSame($expectedExpiresAt, $payload->getExpiresAt());
    }

    /**
     * @testdox PB.5 build uses rawCommand when available
     */
    public function testBuildUsesRawCommandWhenAvailable(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $event->setRawCommand('report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);

        $this->assertSame('report:daily', $payload->getCommand());
    }

    /**
     * @testdox PB.6 build returns Payload with lockKey containing timestamp for normal events
     */
    public function testBuildReturnsLockKeyWithTimestampForNormalEvents(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);

        $this->assertStringContainsString((string) $dueAt->getTimestamp(), $payload->getLockKey());
    }

    /**
     * @testdox PB.7 build returns Payload with stable lockKey for withoutOverlapping events
     */
    public function testBuildReturnsStableLockKeyForWithoutOverlappingEvents(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $event->withoutOverlapping();
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);

        $this->assertStringNotContainsString((string) $dueAt->getTimestamp(), $payload->getLockKey());
    }

    /**
     * @testdox PB.8 toJson from built payload contains all required fields
     */
    public function testToJsonFromBuiltPayloadContainsAllRequiredFields(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600);
        $decoded = json_decode($payload->toJson(), true);

        $this->assertArrayHasKey('command', $decoded);
        $this->assertArrayHasKey('mutexName', $decoded);
        $this->assertArrayHasKey('dueAt', $decoded);
        $this->assertArrayHasKey('lockKey', $decoded);
        $this->assertArrayHasKey('expiresAt', $decoded);
    }
}
