<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;

class LegacyExceptionReporterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (version_compare(\Illuminate\Foundation\Application::VERSION, '7.0.0', '>=')) {
            self::markTestSkipped('LegacyExceptionReporter is used only on Laravel 6.');
        }
    }
    /**
     * @testdox LER.1 Delegates Exception as-is to handler
     */
    public function testDelegatesExceptionAsIsToHandler(): void
    {
        $handler = new SpyExceptionHandler();
        $reporter = new LegacyExceptionReporter($handler, new NullLogger());

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
        $reporter = new LegacyExceptionReporter($handler, new NullLogger());

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
     * @testdox LER.3 Logs warning when handler report throws after wrapping
     */
    public function testLogsWarningWhenHandlerReportThrowsAfterWrapping(): void
    {
        $handler = new SpyExceptionHandler();
        $handler->willThrowOnReport(new \RuntimeException('report failed'));
        $logger = new SpyLogger();
        $reporter = new LegacyExceptionReporter($handler, $logger);

        $reporter->report(new \TypeError('original error'));

        // Should not throw
        $warningLogs = $logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('ExceptionReporter failed', $warningLogs[0]['message']);
        $this->assertSame('report failed', $warningLogs[0]['context']['error']);
        // LegacyExceptionReporter wraps the error, so original message contains the wrapped version
        $this->assertStringContainsString('original error', $warningLogs[0]['context']['original']);
    }

    /**
     * @testdox LER.4 Log context includes original exception object when handler throws
     */
    public function testLogContextIncludesOriginalExceptionWhenHandlerThrows(): void
    {
        $handler = new SpyExceptionHandler();
        $handler->willThrowOnReport(new \RuntimeException('report failed'));
        $logger = new SpyLogger();
        $reporter = new LegacyExceptionReporter($handler, $logger);

        $originalException = new \RuntimeException('original error');
        $reporter->report($originalException);

        $warningLogs = $logger->getLogsByLevel('warning');
        $this->assertCount(1, $warningLogs);
        // Bug: 'original_exception' key is missing from log context
        $this->assertArrayHasKey('original_exception', $warningLogs[0]['context']);
        $this->assertSame($originalException, $warningLogs[0]['context']['original_exception']);
    }
}
