<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

interface ExceptionReporterInterface
{
    /**
     * Report a throwable to the application's error reporting system.
     *
     * This method MUST NOT throw any exceptions.
     *
     * @param \Throwable $e
     * @return void
     */
    public function report(\Throwable $e): void;
}
