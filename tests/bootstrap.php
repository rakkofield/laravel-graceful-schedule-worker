<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
