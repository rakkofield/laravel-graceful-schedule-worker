<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\AlreadyRunningStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\FailedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedStepFunctionsDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionAlreadyExistsException;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\PayloadBuilderInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionInput;
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

    /** @var ExecutionNameGeneratorInterface */
    private $nameGenerator;

    /** @var ClockInterface */
    private $clock;

    /** @var int */
    private $lockTtlSeconds;

    /** @var PayloadBuilderInterface */
    private $payloadBuilder;

    /**
     * @param StepFunctionsClientInterface $client
     * @param ExecutionNameGeneratorInterface $nameGenerator
     * @param ClockInterface $clock
     * @param int $lockTtlSeconds
     * @param PayloadBuilderInterface $payloadBuilder
     */
    public function __construct(
        StepFunctionsClientInterface $client,
        ExecutionNameGeneratorInterface $nameGenerator,
        ClockInterface $clock,
        int $lockTtlSeconds,
        PayloadBuilderInterface $payloadBuilder
    ) {
        $this->client = $client;
        $this->nameGenerator = $nameGenerator;
        $this->clock = $clock;
        $this->lockTtlSeconds = $lockTtlSeconds;
        $this->payloadBuilder = $payloadBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt
    ): DispatchResultInterface {
        $mutexName = $event->mutexName();
        $payload = $this->payloadBuilder->build($event, $dueAt, $this->lockTtlSeconds);
        $command = $payload->getCommand();
        $executionName = $this->nameGenerator->generate($event, $dueAt);

        try {
            $result = $this->client->startExecution(
                new StartExecutionInput($executionName, $payload->toJson())
            );

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
}
