<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Contracts\Debug\ExceptionHandler;

class ExceptionReporter implements ExceptionReporterInterface
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
            $this->handler->report($e);
        } catch (\Throwable $reportError) {
            // Never crash the worker
        }
    }
}
