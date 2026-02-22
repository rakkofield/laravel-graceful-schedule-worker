<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use InvalidArgumentException;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionAlreadyExistsException;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\MutexNameSanitizer;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsException;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Event dispatcher using Step Functions.
 *
 * Calls the StartExecution API to execute tasks via a State Machine.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Necessary dependencies for StepFunctions integration
 */
class StepFunctionsDispatcher implements ScheduleDispatcherInterface
{
    /** @var StepFunctionsClientInterface */
    private $client;

    /** @var string */
    private $stateMachineArn;

    /** @var ExecutionNameGeneratorInterface */
    private $nameGenerator;

    /** @var ClockInterface */
    private $clock;

    /** @var int */
    private $lockTtlSeconds;

    /** @var MutexNameSanitizer */
    private $sanitizer;

    /**
     * @param StepFunctionsClientInterface $client
     * @param string $stateMachineArn
     * @param ExecutionNameGeneratorInterface $nameGenerator
     * @param ClockInterface $clock
     * @param int $lockTtlSeconds
     * @param MutexNameSanitizer $sanitizer
     */
    public function __construct(
        StepFunctionsClientInterface $client,
        string $stateMachineArn,
        ExecutionNameGeneratorInterface $nameGenerator,
        ClockInterface $clock,
        int $lockTtlSeconds,
        MutexNameSanitizer $sanitizer
    ) {
        if ($stateMachineArn === '') {
            throw new InvalidArgumentException(
                'stateMachineArn cannot be empty.'
                . ' Please set graceful-scheduler.stepfunctions.state_machine_arn in your config.'
            );
        }
        $this->client = $client;
        $this->stateMachineArn = $stateMachineArn;
        $this->nameGenerator = $nameGenerator;
        $this->clock = $clock;
        $this->lockTtlSeconds = $lockTtlSeconds;
        $this->sanitizer = $sanitizer;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt
    ): DispatchResultInterface {
        $mutexName = $event->mutexName();
        $command = $event->getRawCommand() ?? $event->command;
        $executionName = $this->nameGenerator->generate($event, $dueAt);
        $ttl = $dueAt->getTimestamp() + $this->lockTtlSeconds;

        try {
            $input = $this->buildInputJson($command, $mutexName, $dueAt, $event->withoutOverlapping, $ttl);

            $result = $this->client->startExecution([
                'stateMachineArn' => $this->stateMachineArn,
                'name' => $executionName,
                'input' => $input,
            ]);

            return new StartedStepFunctionsDispatchResult(
                $result->getExecutionArn(),
                $executionName,
                $mutexName,
                $command,
                $this->clock->now()
            );
        } catch (ExecutionAlreadyExistsException $e) {
            return new AlreadyRunningStepFunctionsDispatchResult(
                $executionName,
                $mutexName,
                $command,
                $this->clock->now()
            );
        } catch (StepFunctionsException $e) {
            return new FailedStepFunctionsDispatchResult(
                $executionName,
                $mutexName,
                $command,
                $e,
                $this->clock->now()
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * No local cleanup needed since Step Functions runs remotely
     */
    public function cleanup(): void
    {
        // no-op: Step Functions executes remotely
    }

    /**
     * {@inheritdoc}
     *
     * No local stopAll needed since Step Functions runs remotely
     */
    public function stopAll(): void
    {
        // no-op: Step Functions executes remotely
    }

    /**
     * Build the JSON input string for StartExecution.
     *
     * @param string $command
     * @param string $mutexName
     * @param DateTimeInterface $dueAt
     * @param bool $withoutOverlapping
     * @param int $ttl
     * @return string
     * @throws StepFunctionsException if encoding fails
     */
    private function buildInputJson(
        string $command,
        string $mutexName,
        DateTimeInterface $dueAt,
        bool $withoutOverlapping,
        int $ttl
    ): string {
        $lockKey = $withoutOverlapping
            ? $this->sanitizer->buildStableKey($mutexName)
            : $this->sanitizer->buildIdentifier($mutexName, (string) $dueAt->getTimestamp());

        $encoded = json_encode([
            'command' => $command,
            'mutexName' => $mutexName,
            'dueAt' => $dueAt->format(DateTimeInterface::ATOM),
            'lockKey' => $lockKey,
            'ttl' => $ttl,
        ]);
        if ($encoded === false) {
            throw new StepFunctionsException('Failed to encode input JSON: ' . json_last_error_msg());
        }
        return $encoded;
    }
}
