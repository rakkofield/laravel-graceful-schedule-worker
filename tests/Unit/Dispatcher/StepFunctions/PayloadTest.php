<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use PHPUnit\Framework\TestCase;

/**
 * @testdox Payload
 */
class PayloadTest extends TestCase
{
    /**
     * @testdox PY.1 toJson returns valid JSON with all fields
     */
    public function testToJsonReturnsValidJsonWithAllFields(): void
    {
        $payload = new Payload(
            ['php', 'artisan', 'report:daily'],
            'framework-schedule-run-abc123',
            '2024-01-15T10:30:00+09:00',
            'framework-schedule-run-abc123_1705282200',
            1705285800,
            1705282200,
            3540
        );

        $json = $payload->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame(['php', 'artisan', 'report:daily'], $decoded['command']);
        $this->assertSame('framework-schedule-run-abc123', $decoded['mutexName']);
        $this->assertSame('2024-01-15T10:30:00+09:00', $decoded['dueAt']);
        $this->assertSame('framework-schedule-run-abc123_1705282200', $decoded['lockKey']);
        $this->assertSame(1705285800, $decoded['expiresAt']);
        $this->assertSame(1705282200, $decoded['dispatchedAt']);
        $this->assertSame(3540, $decoded['timeoutSeconds']);
    }

    /**
     * @testdox PY.2 Getters return constructor values
     */
    public function testGettersReturnConstructorValues(): void
    {
        $payload = new Payload(
            ['php', 'artisan', 'test'],
            'my-mutex',
            '2024-01-15T10:30:00+09:00',
            'my-lock-key',
            3600,
            1705282200,
            3540
        );

        $this->assertInstanceOf(PayloadInterface::class, $payload);
        $this->assertSame(['php', 'artisan', 'test'], $payload->getCommand());
        $this->assertSame('my-mutex', $payload->getMutexName());
        $this->assertSame('2024-01-15T10:30:00+09:00', $payload->getDueAt());
        $this->assertSame('my-lock-key', $payload->getLockKey());
        $this->assertSame(3600, $payload->getExpiresAt());
        $this->assertSame(1705282200, $payload->getDispatchedAt());
        $this->assertSame(3540, $payload->getTimeoutSeconds());
    }

    /**
     * @testdox PY.3 toJson throws PayloadEncodingException on encoding failure
     */
    public function testToJsonThrowsOnEncodingFailure(): void
    {
        $payload = new Payload(
            ["\xFF\xFE"],
            'mutex',
            '2024-01-15T10:30:00+09:00',
            'lock-key',
            3600,
            1705282200,
            3540
        );

        $this->expectException(PayloadEncodingException::class);
        $this->expectExceptionMessage('Failed to encode input JSON');

        $payload->toJson();
    }

    /**
     * @testdox PY.4 toJson produces exactly 7 keys
     */
    public function testToJsonProducesExactlySevenKeys(): void
    {
        $payload = new Payload(
            ['command'],
            'mutex',
            '2024-01-15T10:30:00+09:00',
            'lock-key',
            3600,
            1705282200,
            3540
        );

        $decoded = json_decode($payload->toJson(), true);

        $this->assertCount(7, $decoded);
        $expectedKeys = [
            'command',
            'mutexName',
            'dueAt',
            'lockKey',
            'expiresAt',
            'dispatchedAt',
            'timeoutSeconds',
        ];
        $this->assertSame($expectedKeys, array_keys($decoded));
    }

    /**
     * @testdox PY.5 timeoutSeconds is carried independently of expiresAt and dispatchedAt
     */
    public function testTimeoutSecondsIsCarriedIndependentlyOfOtherIntFields(): void
    {
        // Three distinct ints so a mis-wired constructor argument cannot pass.
        $payload = new Payload(
            ['command'],
            'mutex',
            '2024-01-15T10:30:00+09:00',
            'lock-key',
            1705285800,
            1705282200,
            1800
        );

        $decoded = json_decode($payload->toJson(), true);

        $this->assertSame(1800, $payload->getTimeoutSeconds());
        $this->assertSame(1800, $decoded['timeoutSeconds']);
        $this->assertIsInt($decoded['timeoutSeconds']);
    }

    /**
     * @testdox PY.6 Construction rejects a zero timeoutSeconds
     */
    public function testConstructionRejectsZeroTimeoutSeconds(): void
    {
        // Step Functions resolves a non-positive TimeoutSecondsPath to a non-retryable
        // States.Runtime, so a required argument alone is not enough.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutSeconds must be at least 1 second, got 0');

        new Payload(['command'], 'mutex', '2024-01-15T10:30:00+09:00', 'lock-key', 3600, 1705282200, 0);
    }

    /**
     * @testdox PY.7 Construction rejects a negative timeoutSeconds
     */
    public function testConstructionRejectsNegativeTimeoutSeconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutSeconds must be at least 1 second, got -30');

        new Payload(['command'], 'mutex', '2024-01-15T10:30:00+09:00', 'lock-key', 3600, 1705282200, -30);
    }
}
