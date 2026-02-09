<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Contracts\Debug\ExceptionHandler;

class LegacyExceptionReporter implements ExceptionReporterInterface
{
    /** @var ExceptionHandler */
    private $handler;

    public function __construct(ExceptionHandler $handler)
    {
        $this->handler = $handler;
    }

    public function report(\Throwable $e): void
    {
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
            // Never crash the worker
        }
    }
}
