<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeSchedulingMutex;
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

    /** @var DateTimeImmutable */
    private $dispatchedAt;

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
        $this->app->bind(SchedulingMutex::class, function () {
            return new FakeSchedulingMutex();
        });
        $this->dispatchedAt = new DateTimeImmutable('2024-01-15T10:30:05+09:00');
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

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $this->assertInstanceOf(PayloadInterface::class, $payload);
        $this->assertSame(['php', 'artisan', 'report:daily'], $payload->getCommand());
    }

    /**
     * @testdox PB.2 build returns Payload with correct mutexName
     */
    public function testBuildReturnsPayloadWithCorrectMutexName(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $this->assertSame($event->mutexName(), $payload->getMutexName());
    }

    /**
     * @testdox PB.3 build returns Payload with correct dueAt in ATOM format
     */
    public function testBuildReturnsPayloadWithCorrectDueAt(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $this->assertSame('2024-01-15T10:30:00+09:00', $payload->getDueAt());
    }

    /**
     * @testdox PB.4 build returns Payload with expiresAt = dueAt timestamp + lockTtlSeconds
     */
    public function testBuildReturnsPayloadWithCorrectExpiresAt(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $expectedExpiresAt = $dueAt->getTimestamp() + 3600;
        $this->assertSame($expectedExpiresAt, $payload->getExpiresAt());
    }

    /**
     * @testdox PB.5 build uses rawCommand when available
     */
    public function testBuildUsesRawCommandWhenAvailable(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $event->setRawCommand(['report:daily']);
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $this->assertSame(['report:daily'], $payload->getCommand());
    }

    /**
     * @testdox PB.6 build returns Payload with lockKey containing timestamp for normal events
     */
    public function testBuildReturnsLockKeyWithTimestampForNormalEvents(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

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

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $this->assertStringNotContainsString((string) $dueAt->getTimestamp(), $payload->getLockKey());
    }

    /**
     * @testdox PB.8 toJson from built payload contains all required fields
     */
    public function testToJsonFromBuiltPayloadContainsAllRequiredFields(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);
        $decoded = json_decode($payload->toJson(), true);

        $this->assertArrayHasKey('command', $decoded);
        $this->assertArrayHasKey('mutexName', $decoded);
        $this->assertArrayHasKey('dueAt', $decoded);
        $this->assertArrayHasKey('lockKey', $decoded);
        $this->assertArrayHasKey('expiresAt', $decoded);
        $this->assertArrayHasKey('dispatchedAt', $decoded);
        $this->assertArrayHasKey('timeoutSeconds', $decoded);
    }

    /**
     * @testdox PB.9 build uses event expiresAt when withoutOverlapping has explicit expiresAt
     */
    public function testBuildUsesEventExpiresAtWhenWithoutOverlappingHasExplicitValue(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $event->withoutOverlapping(30);
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $expectedExpiresAt = $dueAt->getTimestamp() + (30 * 60);
        $this->assertSame($expectedExpiresAt, $payload->getExpiresAt());
    }

    /**
     * @testdox PB.10 build uses default event expiresAt when withoutOverlapping called without argument
     */
    public function testBuildUsesDefaultEventExpiresAtWhenWithoutOverlappingCalledWithoutArgument(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $event->withoutOverlapping();
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);

        $expectedExpiresAt = $dueAt->getTimestamp() + (1440 * 60);
        $this->assertSame($expectedExpiresAt, $payload->getExpiresAt());
    }

    /**
     * @testdox PB.11 end-to-end: schedule->command() with inline args produces split command array in payload
     */
    public function testEndToEndScheduleCommandWithInlineArgsProducesSplitCommandArray(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
        $schedule = new ClockAwareSchedule($clock, 'local', null, new TimezoneResolver());

        $event = $schedule->command('update-header-announces 1');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);
        $decoded = json_decode($payload->toJson(), true);

        $this->assertSame(
            ['update-header-announces', '1'],
            $decoded['command']
        );
    }

    /**
     * @testdox PB.12 build returns Payload with dispatchedAt = dispatchedAt timestamp
     */
    public function testBuildReturnsPayloadWithCorrectDispatchedAt(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $dispatchedAt = new DateTimeImmutable('2024-01-15T10:30:05+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $dispatchedAt);

        $this->assertSame($dispatchedAt->getTimestamp(), $payload->getDispatchedAt());
    }

    /**
     * @testdox PB.13 build keeps dispatchedAt independent from dueAt timestamp
     */
    public function testBuildKeepsDispatchedAtIndependentFromDueAt(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $dispatchedAt = new DateTimeImmutable('2024-01-15T10:32:42+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $dispatchedAt);

        $this->assertNotSame($dueAt->getTimestamp(), $payload->getDispatchedAt());
        $this->assertSame($dispatchedAt->getTimestamp(), $payload->getDispatchedAt());
    }

    /**
     * @testdox PB.14 build derives timeoutSeconds as lockTtl - buffer when dispatched exactly at dueAt
     */
    public function testBuildDerivesTimeoutSecondsWithoutDispatchDelay(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $dueAt);

        $this->assertSame(3600 - 60, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PB.15 build shortens timeoutSeconds by the dispatch delay
     */
    public function testBuildShortensTimeoutSecondsByDispatchDelay(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $dispatchedAt = new DateTimeImmutable('2024-01-15T10:32:42+09:00'); // 162s late

        $payload = $this->builder->build($event, $dueAt, 3600, $dispatchedAt);

        $this->assertSame(3600 - 162 - 60, $payload->getTimeoutSeconds());
        // The task must never outlive its lock.
        $this->assertLessThan(
            $payload->getExpiresAt(),
            $payload->getDispatchedAt() + $payload->getTimeoutSeconds()
        );
    }

    /**
     * @testdox PB.16 build honours a custom lock-release buffer
     */
    public function testBuildHonoursCustomLockReleaseBuffer(): void
    {
        $builder = new PayloadBuilder(
            new LockKeyGenerator(new MutexNameSanitizer()),
            new StepFunctionsTimeoutSettings(3600, 300)
        );
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $builder->build($event, $dueAt, 3600, $dueAt);

        $this->assertSame(3600 - 300, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PB.17 build derives timeoutSeconds from the withoutOverlapping TTL
     */
    public function testBuildDerivesTimeoutSecondsFromWithoutOverlappingTtl(): void
    {
        $event = $this->createEvent('php artisan batch:heavy');
        $event->withoutOverlapping(480); // 8 hours
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        // The config lock TTL is ignored once withoutOverlapping declares one.
        $payload = $this->builder->build($event, $dueAt, 3600, $dueAt);

        $this->assertSame((480 * 60) - 60, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PB.18 build keeps tracking the lock while the remaining lifetime meets the floor
     */
    public function testBuildTracksLockAtFloorBoundary(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $dispatchedAt = $dueAt->modify('+480 seconds'); // 600 - 480 - 60 = 60 = floor

        $payload = $this->builder->build($event, $dueAt, 600, $dispatchedAt);

        $this->assertSame(60, $payload->getTimeoutSeconds());
        $this->assertLessThan(
            $payload->getExpiresAt(),
            $payload->getDispatchedAt() + $payload->getTimeoutSeconds()
        );
    }

    /**
     * @testdox PB.19 build falls back to the declared lifetime one second below the floor
     */
    public function testBuildFallsBackJustBelowFloor(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $dispatchedAt = $dueAt->modify('+481 seconds'); // 600 - 481 - 60 = 59, below the floor

        $payload = $this->builder->build($event, $dueAt, 600, $dispatchedAt);

        // The lock can no longer host the run, so the timeout stops tracking it.
        $this->assertSame(600 - 60, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PB.20 build falls back to the declared lifetime when the lock has already expired
     */
    public function testBuildFallsBackWhenLockAlreadyExpired(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $dispatchedAt = $dueAt->modify('+900 seconds'); // dispatched past expiresAt

        $payload = $this->builder->build($event, $dueAt, 600, $dispatchedAt);

        $this->assertSame(600 - 60, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PB.21 build puts the derived timeoutSeconds into the JSON payload
     */
    public function testBuildPutsDerivedTimeoutSecondsIntoJson(): void
    {
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $this->dispatchedAt);
        $decoded = json_decode($payload->toJson(), true);

        $this->assertSame(3600 - 5 - 60, $decoded['timeoutSeconds']);
    }

    /**
     * @testdox PB.22 build gives a withoutOverlapping window narrower than the buffer the floor
     */
    public function testBuildReturnsFloorForNarrowWithoutOverlappingWindow(): void
    {
        $event = $this->createEvent('php artisan cache:prune');
        $event->withoutOverlapping(1); // 60s lifetime, exactly the release buffer
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $dueAt);

        // Without the floor this would be a 1-second timeout on every single run.
        $this->assertSame(
            StepFunctionsTimeoutSettings::DEFAULT_MIN_TASK_TIMEOUT,
            $payload->getTimeoutSeconds()
        );
    }

    /**
     * @testdox PB.23 build gives a recovery dispatch hours late the declared lifetime
     */
    public function testBuildGivesRecoveryDispatchDeclaredLifetime(): void
    {
        // Shape of DefaultScheduleOrchestrator::recoverMissedEvent(): dueAt is the missed
        // slot, dispatchedAt is recovery wallclock, so the lock expired long ago.
        $event = $this->createEvent('php artisan reports:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T02:00:00+09:00');
        $dispatchedAt = new DateTimeImmutable('2024-01-15T08:00:00+09:00'); // 6 hours late

        $payload = $this->builder->build($event, $dueAt, 3600, $dispatchedAt);

        $this->assertSame(3600 - 60, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PB.25 build uses a timeoutAfter() declaration instead of the derived value
     */
    public function testBuildUsesTimeoutAfterDeclaration(): void
    {
        $event = $this->createEvent('php artisan batch:heavy');
        $event->withoutOverlapping(60)->timeoutAfter(1800);
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $dueAt);

        $this->assertSame(1800, $payload->getTimeoutSeconds());
        // The lock still outlives the task.
        $this->assertLessThan(
            $payload->getExpiresAt(),
            $payload->getDispatchedAt() + $payload->getTimeoutSeconds()
        );
    }

    /**
     * @testdox PB.26 build caps a timeoutAfter() declaration at the remaining lock lifetime
     */
    public function testBuildCapsTimeoutAfterAtRemainingLockLifetime(): void
    {
        // withoutOverlapping is absent, so the config lock TTL bounds the run and the
        // event cannot know it at definition time - the cap has to happen here.
        $event = $this->createEvent('php artisan report:daily');
        $event->timeoutAfter(7200);
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $this->builder->build($event, $dueAt, 3600, $dueAt);

        $this->assertSame(3600 - 60, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PB.24 build without explicit settings uses the documented defaults
     */
    public function testBuildWithoutExplicitSettingsUsesDefaults(): void
    {
        $builder = new PayloadBuilder(new LockKeyGenerator(new MutexNameSanitizer()));
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $payload = $builder->build($event, $dueAt, 3600, $dueAt);

        $this->assertSame(
            3600 - StepFunctionsTimeoutSettings::DEFAULT_LOCK_RELEASE_BUFFER,
            $payload->getTimeoutSeconds()
        );
    }
}
