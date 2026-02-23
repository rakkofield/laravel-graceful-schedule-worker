<?php

return [
    'name' => 'GracefulScheduleWorker Test',
    'env' => 'testing',
    'debug' => true,
    'providers' => [
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider::class,
        RakkoInc\LaravelGracefulScheduleWorker\Providers\StepFunctionsServiceProvider::class,
    ],
    'aliases' => [],
];
