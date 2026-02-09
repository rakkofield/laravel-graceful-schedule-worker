<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use PHPUnit\Framework\TestCase;

class ExceptionReporterTest extends TestCase
{
    /**
     * @testdox ER.1 Delegates Exception to handler
     */
    public function testDelegatesExceptionToHandler(): void
    {
        $handler = new SpyExceptionHandler();
        $reporter = new ExceptionReporter($handler);

        $exception = new \RuntimeException('test error');
        $reporter->report($exception);

        $this->assertSame(1, $handler->getReportedCount());
        $this->assertSame($exception, $handler->getReported()[0]);
    }

    /**
     * @testdox ER.2 Delegates Error (non-Exception) to handler
     */
    public function testDelegatesErrorToHandler(): void
    {
        $handler = new SpyExceptionHandler();
        $reporter = new ExceptionReporter($handler);

        $error = new \TypeError('unexpected type');
        $reporter->report($error);

        $this->assertSame(1, $handler->getReportedCount());
        $this->assertSame($error, $handler->getReported()[0]);
    }

    /**
     * @testdox ER.3 Swallows exception thrown by handler report
     */
    public function testSwallowsExceptionThrownByHandlerReport(): void
    {
        $handler = new SpyExceptionHandler();
        $handler->willThrowOnReport(new \RuntimeException('report failed'));
        $reporter = new ExceptionReporter($handler);

        $reporter->report(new \RuntimeException('original error'));

        // Should not throw - the test passes if we reach here
        $this->assertTrue(true);
    }
}
