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
            1705282200
        );

        $json = $payload->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame(['php', 'artisan', 'report:daily'], $decoded['command']);
        $this->assertSame('framework-schedule-run-abc123', $decoded['mutexName']);
        $this->assertSame('2024-01-15T10:30:00+09:00', $decoded['dueAt']);
        $this->assertSame('framework-schedule-run-abc123_1705282200', $decoded['lockKey']);
        $this->assertSame(1705285800, $decoded['expiresAt']);
        $this->assertSame(1705282200, $decoded['dispatchedAt']);
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
            1705282200
        );

        $this->assertInstanceOf(PayloadInterface::class, $payload);
        $this->assertSame(['php', 'artisan', 'test'], $payload->getCommand());
        $this->assertSame('my-mutex', $payload->getMutexName());
        $this->assertSame('2024-01-15T10:30:00+09:00', $payload->getDueAt());
        $this->assertSame('my-lock-key', $payload->getLockKey());
        $this->assertSame(3600, $payload->getExpiresAt());
        $this->assertSame(1705282200, $payload->getDispatchedAt());
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
            1705282200
        );

        $this->expectException(PayloadEncodingException::class);
        $this->expectExceptionMessage('Failed to encode input JSON');

        $payload->toJson();
    }

    /**
     * @testdox PY.4 toJson produces exactly 6 keys
     */
    public function testToJsonProducesExactlySixKeys(): void
    {
        $payload = new Payload(
            ['command'],
            'mutex',
            '2024-01-15T10:30:00+09:00',
            'lock-key',
            3600,
            1705282200
        );

        $decoded = json_decode($payload->toJson(), true);

        $this->assertCount(6, $decoded);
        $expectedKeys = ['command', 'mutexName', 'dueAt', 'lockKey', 'expiresAt', 'dispatchedAt'];
        $this->assertSame($expectedKeys, array_keys($decoded));
    }
}
