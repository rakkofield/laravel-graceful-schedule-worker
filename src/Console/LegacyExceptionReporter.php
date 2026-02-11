<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Psr\Log\LoggerInterface;

class LegacyExceptionReporter implements ExceptionReporterInterface
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
        $original = $e;
        try {
            if (!$e instanceof \Exception) {
                $e = new \ErrorException(
                    sprintf('[%s] %s', get_class($e), $e->getMessage()),
                    0,
                    E_ERROR,
                    $e->getFile(),
                    $e->getLine(),
                    $e
                );
            }
            $this->handler->report($e);
        } catch (\Throwable $reportError) {
            // Never crash the worker; log the reporting failure
            $this->logger->warning('[GracefulScheduleWorker] ExceptionReporter failed', [
                'error' => $reportError->getMessage(),
                'original' => $e->getMessage(),
                'original_exception' => $original,
                'exception' => $reportError,
            ]);
        }
    }
}
