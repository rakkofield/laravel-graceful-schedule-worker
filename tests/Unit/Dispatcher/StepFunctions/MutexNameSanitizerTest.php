<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use PHPUnit\Framework\TestCase;

/**
 * @testdox MutexNameSanitizer
 */
class MutexNameSanitizerTest extends TestCase
{
    /**
     * @testdox MNS.1 Replaces slashes with hyphens
     */
    public function testReplacesSlashesWithHyphens(): void
    {
        $result = MutexNameSanitizer::sanitize('framework/schedule-abc123');

        $this->assertSame('framework-schedule-abc123', $result);
    }

    /**
     * @testdox MNS.2 Replaces colons with hyphens
     */
    public function testReplacesColonsWithHyphens(): void
    {
        $result = MutexNameSanitizer::sanitize('report:daily');

        $this->assertSame('report-daily', $result);
    }

    /**
     * @testdox MNS.3 Preserves allowed characters (alphanumeric, underscore, hyphen)
     */
    public function testPreservesAllowedCharacters(): void
    {
        $result = MutexNameSanitizer::sanitize('my_task-123');

        $this->assertSame('my_task-123', $result);
    }

    /**
     * @testdox MNS.4 Replaces spaces with hyphens
     */
    public function testReplacesSpacesWithHyphens(): void
    {
        $result = MutexNameSanitizer::sanitize('my task name');

        $this->assertSame('my-task-name', $result);
    }

    /**
     * @testdox MNS.5 Replaces multiple invalid characters
     */
    public function testReplacesMultipleInvalidCharacters(): void
    {
        $result = MutexNameSanitizer::sanitize('php artisan report:daily --force');

        $this->assertSame('php-artisan-report-daily---force', $result);
    }

    /**
     * @testdox MNS.6 Returns empty string for empty input
     */
    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $result = MutexNameSanitizer::sanitize('');

        $this->assertSame('', $result);
    }

    /**
     * @testdox MNS.7 Result contains only valid characters
     */
    public function testResultContainsOnlyValidCharacters(): void
    {
        $result = MutexNameSanitizer::sanitize("a/b:c d\te!f@g#h");

        $this->assertRegExp('/^[a-zA-Z0-9_-]*$/', $result);
    }

    /**
     * @testdox MNS.8 buildIdentifier combines sanitized mutex and timestamp
     */
    public function testBuildIdentifierCombinesSanitizedMutexAndTimestamp(): void
    {
        $result = MutexNameSanitizer::buildIdentifier('framework/schedule', '1704067200');

        $this->assertSame('framework-schedule_1704067200', $result);
    }

    /**
     * @testdox MNS.9 buildIdentifier truncates with hash when exceeding 80 characters
     */
    public function testBuildIdentifierTruncatesWithHashWhenExceeding80Characters(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters
        $result = MutexNameSanitizer::buildIdentifier($longMutex, '1704067200');

        $this->assertLessThanOrEqual(80, strlen($result));
    }

    /**
     * @testdox MNS.10 buildIdentifier truncated result contains 16-char hex hash suffix
     */
    public function testBuildIdentifierTruncatedResultContainsHashSuffix(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters
        $result = MutexNameSanitizer::buildIdentifier($longMutex, '1704067200');

        $parts = explode('_', $result);
        $lastPart = end($parts);
        $this->assertEquals(16, strlen($lastPart));
        $this->assertTrue(ctype_xdigit($lastPart));
    }

    /**
     * @testdox MNS.11 buildIdentifier does not truncate identifiers within 80 characters
     */
    public function testBuildIdentifierDoesNotTruncateShortIdentifiers(): void
    {
        $result = MutexNameSanitizer::buildIdentifier('short-task', '1704067200');

        $this->assertSame('short-task_1704067200', $result);
    }

    /**
     * @testdox MNS.12 buildIdentifier is deterministic
     */
    public function testBuildIdentifierIsDeterministic(): void
    {
        $result1 = MutexNameSanitizer::buildIdentifier('my-task', '1704067200');
        $result2 = MutexNameSanitizer::buildIdentifier('my-task', '1704067200');

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox MNS.13 buildStableKey returns sanitized key without timestamp
     */
    public function testBuildStableKeyReturnsSanitizedKeyWithoutTimestamp(): void
    {
        $result = MutexNameSanitizer::buildStableKey('framework/schedule-abc123');

        $this->assertSame('framework-schedule-abc123', $result);
    }

    /**
     * @testdox MNS.14 buildStableKey truncates with hash when exceeding 80 characters
     */
    public function testBuildStableKeyTruncatesWithHashWhenExceeding80Characters(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters

        $result = MutexNameSanitizer::buildStableKey($longMutex);

        $this->assertLessThanOrEqual(80, strlen($result));
    }

    /**
     * @testdox MNS.15 buildStableKey does not truncate keys within 80 characters
     */
    public function testBuildStableKeyDoesNotTruncateShortKeys(): void
    {
        $result = MutexNameSanitizer::buildStableKey('short-task');

        $this->assertSame('short-task', $result);
    }

    /**
     * @testdox MNS.16 buildStableKey is deterministic
     */
    public function testBuildStableKeyIsDeterministic(): void
    {
        $result1 = MutexNameSanitizer::buildStableKey('my-task');
        $result2 = MutexNameSanitizer::buildStableKey('my-task');

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox MNS.17 buildStableKey result contains only valid characters
     */
    public function testBuildStableKeyResultContainsOnlyValidCharacters(): void
    {
        $result = MutexNameSanitizer::buildStableKey('php artisan report:daily --force');

        $this->assertRegExp('/^[a-zA-Z0-9_-]+$/', $result);
    }

    /**
     * @testdox MNS.18 buildStableKey truncated result contains 16-char hex hash suffix
     */
    public function testBuildStableKeyTruncatedResultContainsHashSuffix(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100 characters

        $result = MutexNameSanitizer::buildStableKey($longMutex);

        $parts = explode('_', $result);
        $lastPart = end($parts);
        $this->assertEquals(16, strlen($lastPart));
        $this->assertTrue(ctype_xdigit($lastPart));
    }
}
