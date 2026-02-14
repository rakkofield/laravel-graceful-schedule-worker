<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker;

use PHPUnit\Framework\TestCase;

/**
 * @testdox ExceptionFormatter
 */
class ExceptionFormatterTest extends TestCase
{
    /**
     * @testdox EF.1 Formats exception with class name and message
     */
    public function testFormatsExceptionWithClassNameAndMessage(): void
    {
        $e = new \RuntimeException('Something went wrong');

        $result = ExceptionFormatter::format($e);

        $this->assertSame('RuntimeException: Something went wrong', $result);
    }

    /**
     * @testdox EF.2 Formats Error with class name and message
     */
    public function testFormatsErrorWithClassNameAndMessage(): void
    {
        $e = new \TypeError('Invalid argument');

        $result = ExceptionFormatter::format($e);

        $this->assertSame('TypeError: Invalid argument', $result);
    }

    /**
     * @testdox EF.3 Handles empty message
     */
    public function testHandlesEmptyMessage(): void
    {
        $e = new \LogicException('');

        $result = ExceptionFormatter::format($e);

        $this->assertSame('LogicException: ', $result);
    }
}
