<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class TestExceptionHandler extends ExceptionHandler
{
    /** @var array<int, class-string<\Throwable>> */
    protected $dontReport = [];
}
