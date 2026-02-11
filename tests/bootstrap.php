<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Detect Laravel version and alias version-specific test helpers.
 *
 * Laravel 7+ ships with Symfony 5 which uses typed signatures:
 *   - ExceptionHandler methods use Throwable type hints
 *   - Process::signal(int $signal): void
 *   - Process::stop(float $timeout, ?int $signal): ?int
 *
 * Laravel 6 ships with Symfony 4 which uses untyped signatures:
 *   - ExceptionHandler methods use Exception type hints
 *   - Process::signal($signal)
 *   - Process::stop($timeout, $signal)
 */

$laravelVersion = \Illuminate\Foundation\Application::VERSION;
$isLaravel7OrLater = version_compare($laravelVersion, '7.0.0', '>=');

if ($isLaravel7OrLater) {
    class_alias(
        \RakkoInc\LaravelGracefulScheduleWorker\Console\SpyExceptionHandlerLaravel7::class,
        \RakkoInc\LaravelGracefulScheduleWorker\Console\SpyExceptionHandler::class
    );
    class_alias(
        \RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcessLaravel7::class,
        \RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcess::class
    );
} else {
    class_alias(
        \RakkoInc\LaravelGracefulScheduleWorker\Console\SpyExceptionHandlerLaravel6::class,
        \RakkoInc\LaravelGracefulScheduleWorker\Console\SpyExceptionHandler::class
    );
    class_alias(
        \RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcessLaravel6::class,
        \RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcess::class
    );
}
