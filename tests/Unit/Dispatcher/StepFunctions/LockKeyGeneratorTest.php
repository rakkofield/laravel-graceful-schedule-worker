<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\StubLongMutexEvent;

/**
 * @testdox LockKeyGenerator
 */
class LockKeyGeneratorTest extends TestCase
{
    /** @var LockKeyGenerator */
    private $generator;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var Container */
    private $app;

    /** @var FixedClock */
    private $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new LockKeyGenerator(new MutexNameSanitizer());
        $this->mutex = new FakeEventMutex();
        $this->clock = new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'));
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
     * @param string $mutexName
     * @return StubLongMutexEvent
     */
    private function createEvent(string $mutexName): StubLongMutexEvent
    {
        return new StubLongMutexEvent($this->mutex, 'php artisan dummy', $mutexName, $this->clock);
    }

    /**
     * @testdox LKG.1 Normal event produces lockKey with timestamp
     */
    public function testNormalEventProducesLockKeyWithTimestamp(): void
    {
        $event = $this->createEvent('framework/schedule:run');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate($event, $dueAt);

        $this->assertStringContainsString((string) $dueAt->getTimestamp(), $lockKey);
    }

    /**
     * @testdox LKG.2 withoutOverlapping event produces lockKey without timestamp
     */
    public function testWithoutOverlappingProducesLockKeyWithoutTimestamp(): void
    {
        $event = $this->createEvent('framework/schedule:run');
        $event->withoutOverlapping();
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate($event, $dueAt);

        $this->assertStringNotContainsString((string) $dueAt->getTimestamp(), $lockKey);
    }

    /**
     * @testdox LKG.3 withoutOverlapping produces same lockKey for different dueAt
     */
    public function testWithoutOverlappingProducesSameLockKeyForDifferentDueAt(): void
    {
        $event = $this->createEvent('my-task');
        $event->withoutOverlapping();
        $dueAt1 = new DateTimeImmutable('2024-01-15T10:00:00+09:00');
        $dueAt2 = new DateTimeImmutable('2024-01-15T11:00:00+09:00');

        $lockKey1 = $this->generator->generate($event, $dueAt1);
        $lockKey2 = $this->generator->generate($event, $dueAt2);

        $this->assertSame($lockKey1, $lockKey2);
    }

    /**
     * @testdox LKG.4 Normal event produces different lockKey for different dueAt
     */
    public function testNormalEventProducesDifferentLockKeyForDifferentDueAt(): void
    {
        $event = $this->createEvent('my-task');
        $dueAt1 = new DateTimeImmutable('2024-01-15T10:00:00+09:00');
        $dueAt2 = new DateTimeImmutable('2024-01-15T11:00:00+09:00');

        $lockKey1 = $this->generator->generate($event, $dueAt1);
        $lockKey2 = $this->generator->generate($event, $dueAt2);

        $this->assertNotSame($lockKey1, $lockKey2);
    }

    /**
     * @testdox LKG.5 Sanitizes invalid characters in lockKey
     */
    public function testSanitizesInvalidCharactersInLockKey(): void
    {
        $event = $this->createEvent('framework/schedule:run');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate($event, $dueAt);

        $this->assertStringNotContainsString('/', $lockKey);
        $this->assertStringNotContainsString(':', $lockKey);
    }

    /**
     * @testdox LKG.6 lockKey is at most 80 characters
     */
    public function testLockKeyIsAtMost80Characters(): void
    {
        $longMutex = str_repeat('abcdefghij', 10);
        $event = $this->createEvent($longMutex);
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($lockKey));
    }

    /**
     * @testdox LKG.7 Same input produces same output (deterministic)
     */
    public function testIsDeterministic(): void
    {
        $event = $this->createEvent('my-task');
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey1 = $this->generator->generate($event, $dueAt);
        $lockKey2 = $this->generator->generate($event, $dueAt);

        $this->assertSame($lockKey1, $lockKey2);
    }

    /**
     * @testdox LKG.8 withoutOverlapping lockKey is at most 80 characters
     */
    public function testWithoutOverlappingLockKeyIsAtMost80Characters(): void
    {
        $longMutex = str_repeat('abcdefghij', 10);
        $event = $this->createEvent($longMutex);
        $event->withoutOverlapping();
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($lockKey));
    }

    /**
     * @testdox LKG.9 withoutOverlapping lockKey sanitizes invalid characters
     */
    public function testWithoutOverlappingLockKeySanitizesInvalidCharacters(): void
    {
        $event = $this->createEvent('framework/schedule:run');
        $event->withoutOverlapping();
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate($event, $dueAt);

        $this->assertStringNotContainsString('/', $lockKey);
        $this->assertStringNotContainsString(':', $lockKey);
    }
}
