<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Psr\Log\LoggerInterface;

class ExceptionReporter implements ExceptionReporterInterface
{
    /** @var ExceptionHandler */
    private $handler;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(ExceptionHandler $handler, LoggerInterface $logger)
    {
        $this->handler = $handler;
        $this->logger = $logger;
    }

    public function report(\Throwable $e): void
    {
        try {
            $this->handler->report($e);
        } catch (\Throwable $reportError) {
            // Never crash the worker; log the reporting failure
            $this->logger->error('ExceptionReporter failed', [
                'error' => $reportError->getMessage(),
                'original' => $e->getMessage(),
                'original_exception' => $e,
                'exception' => $reportError,
            ]);
        }
    }
}
