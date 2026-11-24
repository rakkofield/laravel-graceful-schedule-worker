<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Symfony Process 6+ (Laravel 9+) declares signal(): static.
 */
$signalReturnsStatic = (new \ReflectionMethod(\Symfony\Component\Process\Process::class, 'signal'))
    ->hasReturnType();
class_alias(
    $signalReturnsStatic
        ? \RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcessSymfony6::class
        : \RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcessSymfony5::class,
    \RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StubProcess::class
);

/*
 * psr/simple-cache 3 (PHP 8+) declares return types on CacheInterface,
 * which an untyped Repository fake cannot satisfy.
 */
class_alias(
    (new \ReflectionMethod(\Psr\SimpleCache\CacheInterface::class, 'get'))->hasReturnType()
        ? \RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStorePsr3::class
        : \RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStoreLegacy::class,
    \RakkoInc\LaravelGracefulScheduleWorker\Tracker\FakeCacheStore::class
);

/*
 * Enable moto's actual state machine execution mode so integration & E2E tests
 * can verify that the dispatched state machine runs to completion. Skipped
 * silently when motoserver is unreachable (e.g. unit-only runs).
 */
$sfnEndpoint = getenv('SFN_ENDPOINT');
if (is_string($sfnEndpoint) && $sfnEndpoint !== '') {
    try {
        $configurator = new \RakkoInc\LaravelGracefulScheduleWorker\Moto\MotoConfigurator($sfnEndpoint);
        $configurator->reset();
        $configurator->enableStepFunctionsExecution();
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf(
            "[bootstrap] motoserver config skipped: %s\n",
            $e->getMessage()
        ));
    }
}
