<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox Payload
 */
class PayloadTest extends TestCase
{
    /**
     * @testdox PY.1 toJson returns valid JSON with command, mutexName, dueAt, lockKey, and ttl
     */
    public function testToJsonReturnsValidJson(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $payload = new Payload(
            'report:daily',
            'framework/schedule-abc123',
            $dueAt,
            'framework-schedule-abc123_1705282200',
            1705285800
        );

        $json = $payload->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('report:daily', $decoded['command']);
        $this->assertSame('framework/schedule-abc123', $decoded['mutexName']);
        $this->assertSame('2024-01-15T10:30:00+09:00', $decoded['dueAt']);
        $this->assertSame('framework-schedule-abc123_1705282200', $decoded['lockKey']);
        $this->assertSame(1705285800, $decoded['ttl']);
    }

    /**
     * @testdox PY.2 toJson throws RuntimeException on encoding failure
     */
    public function testToJsonThrowsOnEncodingFailure(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        // Invalid UTF-8 triggers json_encode failure
        $payload = new Payload("\xFF\xFE", 'mutex', $dueAt, 'lock-key', 9999);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode input JSON');

        $payload->toJson();
    }

    /**
     * @testdox PY.3 dueAt is formatted as ATOM
     */
    public function testDueAtFormattedAsAtom(): void
    {
        $dueAt = new DateTimeImmutable('2024-06-01T00:00:00+00:00');
        $payload = new Payload('echo test', 'mutex', $dueAt, 'lock-key', 9999);

        $json = $payload->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame('2024-06-01T00:00:00+00:00', $decoded['dueAt']);
    }

    /**
     * @testdox PY.4 ttl is an integer in JSON output
     */
    public function testTtlIsIntegerInJson(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $payload = new Payload('test', 'mutex', $dueAt, 'lock-key', 1705285800);

        $json = $payload->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsInt($decoded['ttl']);
        $this->assertSame(1705285800, $decoded['ttl']);
    }
}
