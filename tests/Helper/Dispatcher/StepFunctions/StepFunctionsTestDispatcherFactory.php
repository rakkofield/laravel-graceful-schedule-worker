<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Sfn\SfnClient;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\EventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedDispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StepFunctionsDispatchResultFactory;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctionsDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\TimezoneResolver;

/**
 * Wires a `StepFunctionsDispatcher` with the same collaborator graph the
 * production `StepFunctionsServiceProvider` builds, but with test-supplied
 * inputs (clock, state machine ARN, lock TTL). Bundling the wiring here
 * keeps the calling test focused on the scenario rather than the graph.
 */
final class StepFunctionsTestDispatcherFactory
{
    /**
     * Default dispatch instant used by Step Functions integration / E2E tests.
     * Tests share this so the dispatcher's clock and the expected-payload
     * `dispatchedAt` resolve to the same moment.
     */
    public const DEFAULT_DISPATCH_INSTANT = '2024-01-15 10:00:00';

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
        $resultFactory = new StepFunctionsDispatchResultFactory($clock);

        return new StepFunctionsDispatcher(
            new AwsSfnClientAdapter($sfn, $stateMachineArn),
            $inputFactory,
            $resultFactory
        );
    }

    /**
     * Build the same `Payload` the production graph would produce for a
     * given dispatch. Tests use this to compare the SFN execution output
     * against an expected value computed by the same components, so the
     * test never has to know how `mutexName` / `lockKey` etc. are derived.
     *
     * `$dispatchedAt` must be the same instant the test fed into the
     * Dispatcher's clock so that the expected payload's `dispatchedAt`
     * matches the wire-level value the Dispatcher recorded.
     */
    public static function buildExpectedPayload(
        EventMutex $mutex,
        string $command,
        DateTimeInterface $dueAt,
        int $lockTtlSeconds,
        DateTimeInterface $dispatchedAt
    ): Payload {
        // ClockAwareEvent requires a clock, but the value is unused here:
        // the payload's `dispatchedAt` comes from the explicit argument.
        $clock = new FixedClock(new DateTimeImmutable(self::DEFAULT_DISPATCH_INSTANT));
        $event = new ClockAwareEvent($mutex, $command, $clock, 'local', null, new TimezoneResolver());

        $sanitizer = new MutexNameSanitizer();
        return (new PayloadBuilder(new LockKeyGenerator($sanitizer)))
            ->build($event, $dueAt, $lockTtlSeconds, $dispatchedAt);
    }

    /**
     * Build a one-line diagnostic from a non-success dispatch result.
     * Accepts the broad `DispatchResultInterface` because `dispatchEvent()`
     * can also return `AlreadyRunning` results, which a Started-only guard
     * at the call site forwards into this helper. Failed results contribute
     * their `getError()` string and any `getPrevious()` exception chain;
     * other shapes degrade gracefully to the result class name.
     */
    public static function describeFailure(DispatchResultInterface $result): string
    {
        $parts = [get_class($result)];
        if ($result instanceof FailedDispatchResultInterface) {
            $parts[] = $result->getError();
            $exception = $result->getException();
            if ($exception !== null) {
                for ($prev = $exception->getPrevious(); $prev !== null; $prev = $prev->getPrevious()) {
                    $parts[] = 'caused by ' . get_class($prev) . ': ' . $prev->getMessage();
                }
            }
        }
        return implode(' | ', $parts);
    }
}
