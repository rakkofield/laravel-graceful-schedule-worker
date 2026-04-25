<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Sfn\SfnClient;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;

/**
 * Wires a `StepFunctionsDispatcher` with the same collaborator graph the
 * production `StepFunctionsServiceProvider` builds, but with test-supplied
 * inputs (clock, state machine ARN, lock TTL). Bundling the wiring here
 * keeps the calling test focused on the scenario rather than the graph.
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
     * Build a one-line diagnostic from a failed dispatch result: the
     * `getError()` string and, when an exception is attached, its class
     * plus the `getPrevious()` chain.
     */
    public static function describeFailure(FailedDispatchResultInterface $result): string
    {
        $parts = [get_class($result), $result->getError()];

        $exception = $result->getException();
        if ($exception !== null) {
            for ($prev = $exception->getPrevious(); $prev !== null; $prev = $prev->getPrevious()) {
                $parts[] = 'caused by ' . get_class($prev) . ': ' . $prev->getMessage();
            }
        }
        return implode(' | ', $parts);
    }
}
