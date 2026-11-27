<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;

// routes/console.php is loaded by App\Console\Kernel::commands(). withRouting()
// is not used: Laravel 11.0 fails when it is given only commands.
$app = Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Register the console kernel added in app/Console/Kernel.php, as README describes
// for Laravel 11+. withSingletons() is too late for this,
// because the console kernel is resolved before service providers are registered.
$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

return $app;
