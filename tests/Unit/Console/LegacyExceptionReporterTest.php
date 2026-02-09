<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use PHPUnit\Framework\TestCase;

class LegacyExceptionReporterTest extends TestCase
{
    /**
     * @testdox LER.1 Delegates Exception as-is to handler
     */
    public function testDelegatesExceptionAsIsToHandler(): void
    {
        $handler = new SpyExceptionHandler();
        $reporter = new LegacyExceptionReporter($handler);

        $exception = new \RuntimeException('test error');
        $reporter->report($exception);

        $this->assertSame(1, $handler->getReportedCount());
        $this->assertSame($exception, $handler->getReported()[0]);
    }

    /**
     * @testdox LER.2 Wraps Error in ErrorException for handler
     */
    public function testWrapsErrorInErrorExceptionForHandler(): void
    {
        $handler = new SpyExceptionHandler();
        $reporter = new LegacyExceptionReporter($handler);

        $error = new \TypeError('unexpected type');
        $reporter->report($error);

        $this->assertSame(1, $handler->getReportedCount());

        $reported = $handler->getReported()[0];
        $this->assertInstanceOf(\ErrorException::class, $reported);
        $this->assertSame($error, $reported->getPrevious());
        $this->assertSame($error->getFile(), $reported->getFile());
        $this->assertSame($error->getLine(), $reported->getLine());
        $this->assertStringContainsString('TypeError', $reported->getMessage());
        $this->assertStringContainsString('unexpected type', $reported->getMessage());
    }

    /**
     * @testdox LER.3 Swallows exception thrown by handler report after wrapping
     */
    public function testSwallowsExceptionThrownByHandlerReportAfterWrapping(): void
    {
        $handler = new SpyExceptionHandler();
        $handler->willThrowOnReport(new \RuntimeException('report failed'));
        $reporter = new LegacyExceptionReporter($handler);

        $reporter->report(new \TypeError('original error'));

        // Should not throw - the test passes if we reach here
        $this->assertTrue(true);
    }
}
