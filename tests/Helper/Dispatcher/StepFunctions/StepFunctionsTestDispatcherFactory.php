<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Sfn\SfnClient;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use Throwable;

/**
 * Wires a `StepFunctionsDispatcher` with the same component graph the
 * production `StepFunctionsServiceProvider` builds, but with test-supplied
 * inputs (clock, state machine ARN, lock TTL).
 *
 * The dispatcher composes five pieces (sanitizer, lock-key generator,
 * payload builder, name generator, input factory) — bundling the wiring
 * here keeps the calling test focused on the scenario rather than the
 * graph.
 */
final class StepFunctionsTestDispatcherFactory
{
    public static function create(
        SfnClient $sfn,
        string $stateMachineArn,
        ClockInterface $clock,
        int $lockTtlSeconds
    ): StepFunctionsDispatcher {
        $sanitizer = new MutexNameSanitizer();
        $payloadBuilder = new PayloadBuilder(new LockKeyGenerator($sanitizer));
        $inputFactory = new StartExecutionInputFactory(
            new ExecutionNameGenerator($sanitizer),
            $payloadBuilder,
            $lockTtlSeconds
        );

        return new StepFunctionsDispatcher(
            new AwsSfnClientAdapter($sfn, $stateMachineArn),
            $inputFactory,
            $clock
        );
    }

    /**
     * Build a one-line diagnostic from a non-success dispatch result so a
     * test failure surfaces the underlying SDK exception class, message,
     * and `getPrevious()` chain instead of just the result wrapper class.
     */
    public static function describeFailure(object $result): string
    {
        $parts = [get_class($result)];
        if (method_exists($result, 'getError')) {
            $error = $result->getError();
            $parts[] = $error instanceof Throwable
                ? get_class($error) . ': ' . $error->getMessage()
                : (string) $error;
        }
        if (method_exists($result, 'getException')) {
            $exception = $result->getException();
            if ($exception instanceof Throwable) {
                for ($prev = $exception->getPrevious(); $prev !== null; $prev = $prev->getPrevious()) {
                    $parts[] = 'caused by ' . get_class($prev) . ': ' . $prev->getMessage();
                }
            }
        }
        return implode(' | ', $parts);
    }
}
