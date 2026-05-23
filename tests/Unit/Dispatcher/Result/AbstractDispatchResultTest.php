<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @testdox AbstractDispatchResult
 */
class AbstractDispatchResultTest extends TestCase
{
    /**
     * @testdox ADR.1 getEventIdentifier returns constructor value
     */
    public function testGetEventIdentifierReturnsConstructorValue(): void
    {
        $result = new ConcreteDispatchResult(
            'my-identifier',
            'php artisan test',
            'local',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('my-identifier', $result->getEventIdentifier());
    }

    /**
     * @testdox ADR.2 getEventCommand returns constructor value
     */
    public function testGetEventCommandReturnsConstructorValue(): void
    {
        $result = new ConcreteDispatchResult(
            'id',
            'php artisan schedule:run',
            'local',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('php artisan schedule:run', $result->getEventCommand());
    }

    /**
     * @testdox ADR.3 getDispatcherType returns constructor value
     */
    public function testGetDispatcherTypeReturnsConstructorValue(): void
    {
        $result = new ConcreteDispatchResult(
            'id',
            'cmd',
            'stepfunctions',
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );

        $this->assertSame('stepfunctions', $result->getDispatcherType());
    }

    /**
     * @testdox ADR.4 getDispatchedAt returns constructor value
     */
    public function testGetDispatchedAtReturnsConstructorValue(): void
    {
        $now = new DateTimeImmutable('2024-01-15 12:00:00');
        $result = new ConcreteDispatchResult('id', 'cmd', 'local', $now, new DateTimeImmutable());

        $this->assertSame($now, $result->getDispatchedAt());
    }

    /**
     * @testdox ADR.5 implements DispatchResultInterface
     */
    public function testImplementsDispatchResultInterface(): void
    {
        $result = new ConcreteDispatchResult('id', 'cmd', 'local', new DateTimeImmutable(), new DateTimeImmutable());

        $this->assertInstanceOf(DispatchResultInterface::class, $result);
    }

    /**
     * @testdox ADR.6 getRecordedAt returns constructor value
     */
    public function testGetRecordedAtReturnsConstructorValue(): void
    {
        $recordedAt = new DateTimeImmutable('2024-01-15 12:00:01');
        $result = new ConcreteDispatchResult(
            'id',
            'cmd',
            'local',
            new DateTimeImmutable('2024-01-15 12:00:00'),
            $recordedAt
        );

        $this->assertSame($recordedAt, $result->getRecordedAt());
    }
}
