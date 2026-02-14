<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Logging;

use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\SpyLogger;

/**
 * @testdox PrefixedLogger
 */
class PrefixedLoggerTest extends TestCase
{
    /**
     * @testdox PL.1 Prepends prefix to log message
     */
    public function testPrependsPrefix(): void
    {
        $inner = new SpyLogger();
        $logger = new PrefixedLogger($inner);

        $logger->info('Test message');

        $logs = $inner->getLogsByLevel('info');
        $this->assertCount(1, $logs);
        $this->assertSame('[GracefulScheduleWorker] Test message', $logs[0]['message']);
    }

    /**
     * @testdox PL.2 Passes context through unchanged
     */
    public function testPassesContextThrough(): void
    {
        $inner = new SpyLogger();
        $logger = new PrefixedLogger($inner);

        $context = ['key' => 'value', 'num' => 42];
        $logger->warning('With context', $context);

        $logs = $inner->getLogsByLevel('warning');
        $this->assertCount(1, $logs);
        $this->assertSame($context, $logs[0]['context']);
    }

    /**
     * @testdox PL.3 Works with all log levels
     */
    public function testWorksWithAllLogLevels(): void
    {
        $inner = new SpyLogger();
        $logger = new PrefixedLogger($inner);

        $logger->emergency('emergency msg');
        $logger->alert('alert msg');
        $logger->critical('critical msg');
        $logger->error('error msg');
        $logger->warning('warning msg');
        $logger->notice('notice msg');
        $logger->info('info msg');
        $logger->debug('debug msg');

        $all = $inner->getLogs();
        $this->assertCount(8, $all);

        foreach ($all as $log) {
            $this->assertStringStartsWith('[GracefulScheduleWorker] ', $log['message']);
        }
    }

    /**
     * @testdox PL.4 Preserves log level correctly
     */
    public function testPreservesLogLevel(): void
    {
        $inner = new SpyLogger();
        $logger = new PrefixedLogger($inner);

        $logger->error('Error message');
        $logger->debug('Debug message');

        $errorLogs = $inner->getLogsByLevel('error');
        $this->assertCount(1, $errorLogs);
        $this->assertSame('[GracefulScheduleWorker] Error message', $errorLogs[0]['message']);

        $debugLogs = $inner->getLogsByLevel('debug');
        $this->assertCount(1, $debugLogs);
        $this->assertSame('[GracefulScheduleWorker] Debug message', $debugLogs[0]['message']);
    }
}
