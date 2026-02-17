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
     * @testdox PY.1 toJson returns valid JSON with command, mutexName, and dueAt
     */
    public function testToJsonReturnsValidJson(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        $payload = new Payload('php artisan report:daily', 'framework/schedule-abc123', $dueAt);

        $json = $payload->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('php artisan report:daily', $decoded['command']);
        $this->assertSame('framework/schedule-abc123', $decoded['mutexName']);
        $this->assertSame('2024-01-15T10:30:00+09:00', $decoded['dueAt']);
    }

    /**
     * @testdox PY.2 toJson throws RuntimeException on encoding failure
     */
    public function testToJsonThrowsOnEncodingFailure(): void
    {
        $dueAt = new DateTimeImmutable('2024-01-15T10:30:00+09:00');
        // Invalid UTF-8 triggers json_encode failure
        $payload = new Payload("\xFF\xFE", 'mutex', $dueAt);

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
        $payload = new Payload('echo test', 'mutex', $dueAt);

        $json = $payload->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame('2024-06-01T00:00:00+00:00', $decoded['dueAt']);
    }
}
