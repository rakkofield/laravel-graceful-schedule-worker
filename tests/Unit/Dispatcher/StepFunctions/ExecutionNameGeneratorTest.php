<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\StubLongMutexEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * @testdox ExecutionNameGenerator
 */
class ExecutionNameGeneratorTest extends TestCase
{
    /** @var ExecutionNameGenerator */
    private $generator;

    /** @var FakeEventMutex */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ExecutionNameGenerator(new MutexNameSanitizer());
        $this->mutex = new FakeEventMutex();
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
     * @testdox ENG.1 Generates Execution Name from Event and dueAt
     */
    public function testGeneratesExecutionNameFromEventAndDueAt(): void
    {
        $event = $this->createEvent('my-task');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        // Since mutexName depends on Event's internal format,
        // verify the timestamp portion is included
        $this->assertStringContainsString('1704067200', $result);
    }

    /**
     * @testdox ENG.2 Replaces invalid characters with hyphens
     */
    public function testSanitizesInvalidCharacters(): void
    {
        $event = $this->createEvent('framework/schedule:run');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        // Verify invalid characters are sanitized
        $this->assertStringNotContainsString('/', $result);
        $this->assertStringNotContainsString(':', $result);
    }

    /**
     * @testdox ENG.3 Truncates long names using hash when exceeding 80 characters
     */
    public function testTruncatesLongNamesWithHash(): void
    {
        $longCommand = str_repeat('a', 100);
        $event = $this->createEvent($longCommand);
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($result));
    }

    /**
     * @testdox ENG.4 Same input produces same output (deterministic)
     */
    public function testIsDeterministic(): void
    {
        $event = $this->createEvent('my-task');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result1 = $this->generator->generate($event, $dueAt);
        $result2 = $this->generator->generate($event, $dueAt);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox ENG.5 Long names also produce the same hash from the same input
     */
    public function testLongNamesAreDeterministic(): void
    {
        $longCommand = str_repeat('x', 100);
        $event = $this->createEvent($longCommand);
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result1 = $this->generator->generate($event, $dueAt);
        $result2 = $this->generator->generate($event, $dueAt);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox ENG.6 Names at or below 80 characters are not truncated
     */
    public function testNamesAtOrBelowLimitAreNotTruncated(): void
    {
        // Verify short commands do not include hash
        $event = $this->createEvent('short');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($result));
        // Short inputs do not use hash; Unix timestamp is included
        $this->assertStringContainsString('1704067200', $result);
    }

    /**
     * @testdox ENG.7 Result contains only allowed characters
     */
    public function testResultContainsOnlyValidCharacters(): void
    {
        $event = $this->createEvent('php artisan report:daily --force');
        $dueAt = new DateTimeImmutable('2024-06-15 14:30:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertStringNotContainsString(' ', $result);
        $this->assertStringNotContainsString(':', $result);
    }

    /**
     * @testdox ENG.8 mutexName exceeding 80 chars is truncated with hash to fit within 80 chars
     */
    public function testTruncatesWithHashWhenMutexNameExceeds80Chars(): void
    {
        // Standard Event::mutexName() is always fixed-length (sha1) so never exceeds 80 chars.
        // Use StubLongMutexEvent with a long mutexName to verify the truncation path.
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters
        $event = new StubLongMutexEvent(
            $this->mutex,
            'test',
            $longMutex,
            new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'))
        );
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($result));
    }

    /**
     * @testdox ENG.9 Truncated name contains hash suffix
     */
    public function testTruncatedNameContainsHashSuffix(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters
        $event = new StubLongMutexEvent(
            $this->mutex,
            'test',
            $longMutex,
            new FixedClock(new DateTimeImmutable('2024-01-15 12:00:00'))
        );
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        // Truncated result is mutexPrefix + '_' + 16-char md5 hash
        $parts = explode('_', $result);
        $lastPart = end($parts);
        // Hash portion is 16 hex characters
        $this->assertEquals(16, strlen($lastPart));
        $this->assertTrue(ctype_xdigit($lastPart));
    }
}
