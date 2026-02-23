<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox LockKeyGenerator
 */
class LockKeyGeneratorTest extends TestCase
{
    /** @var LockKeyGenerator */
    private $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new LockKeyGenerator(new MutexNameSanitizer());
    }

    /**
     * @testdox LKG.1 Normal event produces lockKey with timestamp
     */
    public function testNormalEventProducesLockKeyWithTimestamp(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate('framework/schedule:run', $dueAt, false);

        $this->assertStringContainsString((string) $dueAt->getTimestamp(), $lockKey);
    }

    /**
     * @testdox LKG.2 withoutOverlapping event produces lockKey without timestamp
     */
    public function testWithoutOverlappingProducesLockKeyWithoutTimestamp(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate('framework/schedule:run', $dueAt, true);

        $this->assertStringNotContainsString((string) $dueAt->getTimestamp(), $lockKey);
    }

    /**
     * @testdox LKG.3 withoutOverlapping produces same lockKey for different dueAt
     */
    public function testWithoutOverlappingProducesSameLockKeyForDifferentDueAt(): void
    {
        $dueAt1 = new DateTimeImmutable('2024-01-15T10:00:00+09:00');
        $dueAt2 = new DateTimeImmutable('2024-01-15T11:00:00+09:00');

        $lockKey1 = $this->generator->generate('my-task', $dueAt1, true);
        $lockKey2 = $this->generator->generate('my-task', $dueAt2, true);

        $this->assertSame($lockKey1, $lockKey2);
    }

    /**
     * @testdox LKG.4 Normal event produces different lockKey for different dueAt
     */
    public function testNormalEventProducesDifferentLockKeyForDifferentDueAt(): void
    {
        $dueAt1 = new DateTimeImmutable('2024-01-15T10:00:00+09:00');
        $dueAt2 = new DateTimeImmutable('2024-01-15T11:00:00+09:00');

        $lockKey1 = $this->generator->generate('my-task', $dueAt1, false);
        $lockKey2 = $this->generator->generate('my-task', $dueAt2, false);

        $this->assertNotSame($lockKey1, $lockKey2);
    }

    /**
     * @testdox LKG.5 Sanitizes invalid characters in lockKey
     */
    public function testSanitizesInvalidCharactersInLockKey(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate('framework/schedule:run', $dueAt, false);

        $this->assertStringNotContainsString('/', $lockKey);
        $this->assertStringNotContainsString(':', $lockKey);
    }

    /**
     * @testdox LKG.6 lockKey is at most 80 characters
     */
    public function testLockKeyIsAtMost80Characters(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $longMutex = str_repeat('abcdefghij', 10);

        $lockKey = $this->generator->generate($longMutex, $dueAt, false);

        $this->assertLessThanOrEqual(80, strlen($lockKey));
    }

    /**
     * @testdox LKG.7 Same input produces same output (deterministic)
     */
    public function testIsDeterministic(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey1 = $this->generator->generate('my-task', $dueAt, false);
        $lockKey2 = $this->generator->generate('my-task', $dueAt, false);

        $this->assertSame($lockKey1, $lockKey2);
    }

    /**
     * @testdox LKG.8 withoutOverlapping lockKey is at most 80 characters
     */
    public function testWithoutOverlappingLockKeyIsAtMost80Characters(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $longMutex = str_repeat('abcdefghij', 10);

        $lockKey = $this->generator->generate($longMutex, $dueAt, true);

        $this->assertLessThanOrEqual(80, strlen($lockKey));
    }

    /**
     * @testdox LKG.9 withoutOverlapping lockKey sanitizes invalid characters
     */
    public function testWithoutOverlappingLockKeySanitizesInvalidCharacters(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $lockKey = $this->generator->generate('framework/schedule:run', $dueAt, true);

        $this->assertStringNotContainsString('/', $lockKey);
        $this->assertStringNotContainsString(':', $lockKey);
    }
}
