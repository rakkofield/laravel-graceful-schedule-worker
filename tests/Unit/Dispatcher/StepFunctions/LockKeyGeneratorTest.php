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
        $this->generator = new LockKeyGenerator();
    }

    /**
     * @testdox LKG.1 Generates lock key from mutexName and dueAt
     */
    public function testGeneratesLockKeyFromMutexNameAndDueAt(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = $this->generator->generate('framework/schedule-abc123', $dueAt, false);

        $this->assertStringContainsString('framework-schedule-abc123', $result);
        $this->assertStringContainsString((string) $dueAt->getTimestamp(), $result);
    }

    /**
     * @testdox LKG.2 Sanitizes invalid characters in mutexName
     */
    public function testSanitizesInvalidCharacters(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate('framework/schedule:run', $dueAt, false);

        $this->assertStringNotContainsString('/', $result);
        $this->assertStringNotContainsString(':', $result);
    }

    /**
     * @testdox LKG.3 Truncates long keys using hash when exceeding 80 characters
     */
    public function testTruncatesLongKeysWithHash(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($longMutex, $dueAt, false);

        $this->assertLessThanOrEqual(80, strlen($result));
    }

    /**
     * @testdox LKG.4 Same input produces same output (deterministic)
     */
    public function testIsDeterministic(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result1 = $this->generator->generate('my-task', $dueAt, false);
        $result2 = $this->generator->generate('my-task', $dueAt, false);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox LKG.5 Keys at or below 80 characters are not truncated
     */
    public function testKeysAtOrBelowLimitAreNotTruncated(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate('short', $dueAt, false);

        $this->assertLessThanOrEqual(80, strlen($result));
        $this->assertStringContainsString((string) $dueAt->getTimestamp(), $result);
    }

    /**
     * @testdox LKG.6 Truncated key contains hash suffix
     */
    public function testTruncatedKeyContainsHashSuffix(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($longMutex, $dueAt, false);

        $parts = explode('_', $result);
        $lastPart = end($parts);
        $this->assertEquals(16, strlen($lastPart));
        $this->assertTrue(ctype_xdigit($lastPart));
    }

    /**
     * @testdox LKG.7 Result contains only allowed characters
     */
    public function testResultContainsOnlyValidCharacters(): void
    {
        $dueAt = new DateTimeImmutable('2024-06-15 14:30:00');

        $result = $this->generator->generate('php artisan report:daily --force', $dueAt, false);

        $this->assertRegExp('/^[a-zA-Z0-9_-]+$/', $result);
    }

    /**
     * @testdox LKG.8 Different mutexNames produce different lock keys
     */
    public function testDifferentMutexNamesProduceDifferentKeys(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result1 = $this->generator->generate('task-a', $dueAt, false);
        $result2 = $this->generator->generate('task-b', $dueAt, false);

        $this->assertNotSame($result1, $result2);
    }

    /**
     * @testdox LKG.9 Different dueAt produce different lock keys
     */
    public function testDifferentDueAtProduceDifferentKeys(): void
    {
        $dueAt1 = new DateTimeImmutable('2024-01-01 00:00:00');
        $dueAt2 = new DateTimeImmutable('2024-01-02 00:00:00');

        $result1 = $this->generator->generate('task', $dueAt1, false);
        $result2 = $this->generator->generate('task', $dueAt2, false);

        $this->assertNotSame($result1, $result2);
    }

    /**
     * @testdox LKG.10 withoutOverlapping returns key without timestamp
     */
    public function testWithoutOverlappingReturnsKeyWithoutTimestamp(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');

        $result = $this->generator->generate('framework/schedule-abc123', $dueAt, true);

        $this->assertSame('framework-schedule-abc123', $result);
        $this->assertStringNotContainsString((string) $dueAt->getTimestamp(), $result);
    }

    /**
     * @testdox LKG.11 withoutOverlapping produces same key for different dueAt
     */
    public function testWithoutOverlappingProducesSameKeyForDifferentDueAt(): void
    {
        $dueAt1 = new DateTimeImmutable('2024-01-01 00:00:00');
        $dueAt2 = new DateTimeImmutable('2024-01-02 00:00:00');

        $result1 = $this->generator->generate('task', $dueAt1, true);
        $result2 = $this->generator->generate('task', $dueAt2, true);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox LKG.12 withoutOverlapping key respects 80 character limit
     */
    public function testWithoutOverlappingKeyRespectsLengthLimit(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($longMutex, $dueAt, true);

        $this->assertLessThanOrEqual(80, strlen($result));
    }

    /**
     * @testdox LKG.13 withoutOverlapping key contains only valid characters
     */
    public function testWithoutOverlappingKeyContainsOnlyValidCharacters(): void
    {
        $dueAt = new DateTimeImmutable('2024-06-15 14:30:00');

        $result = $this->generator->generate('php artisan report:daily --force', $dueAt, true);

        $this->assertRegExp('/^[a-zA-Z0-9_-]+$/', $result);
    }
}
