<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;

class ExceptionReporterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (version_compare(\Illuminate\Foundation\Application::VERSION, '7.0.0', '<')) {
            self::markTestSkipped('ExceptionReporter is used only on Laravel 7+.');
        }
    }
    /**
     * @testdox ER.1 Delegates Exception to handler
     */
    public function testDelegatesExceptionToHandler(): void
    {
        $handler = new SpyExceptionHandler();
        $reporter = new ExceptionReporter($handler, new NullLogger());

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
        $reporter = new ExceptionReporter($handler, new NullLogger());

        $error = new \TypeError('unexpected type');
        $reporter->report($error);

        $this->assertSame(1, $handler->getReportedCount());
        $this->assertSame($error, $handler->getReported()[0]);
    }

    /**
     * @testdox ER.3 Logs warning when handler report throws
     */
    public function testLogsWarningWhenHandlerReportThrows(): void
    {
        $handler = new SpyExceptionHandler();
        $handler->willThrowOnReport(new \RuntimeException('report failed'));
        $logger = new SpyLogger();
        $reporter = new ExceptionReporter($handler, $logger);

        $reporter->report(new \RuntimeException('original error'));

        // Should not throw
        $warningLogs = $logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('ExceptionReporter failed', $warningLogs[0]['message']);
        $this->assertSame('report failed', $warningLogs[0]['context']['error']);
        $this->assertSame('original error', $warningLogs[0]['context']['original']);
    }

    /**
     * @testdox ER.4 Log context includes original exception object when handler throws
     */
    public function testLogContextIncludesOriginalExceptionWhenHandlerThrows(): void
    {
        $handler = new SpyExceptionHandler();
        $handler->willThrowOnReport(new \RuntimeException('report failed'));
        $logger = new SpyLogger();
        $reporter = new ExceptionReporter($handler, $logger);

        $originalException = new \RuntimeException('original error');
        $reporter->report($originalException);

        $warningLogs = $logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        // Bug: 'original_exception' key is missing from log context
        $this->assertArrayHasKey('original_exception', $warningLogs[0]['context']);
        $this->assertSame($originalException, $warningLogs[0]['context']['original_exception']);
    }
}
