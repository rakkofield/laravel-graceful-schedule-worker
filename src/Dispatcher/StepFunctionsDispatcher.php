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
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\Payload;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsException;
use RakkoInc\LaravelGracefulScheduleWorker\ExceptionFormatter;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Event dispatcher using Step Functions.
 *
 * Calls the StartExecution API to execute tasks via a State Machine.
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

    /**
     * @param StepFunctionsClientInterface $client
     * @param string $stateMachineArn
     * @param ExecutionNameGeneratorInterface $nameGenerator
     * @param ClockInterface $clock
     */
    public function __construct(
        StepFunctionsClientInterface $client,
        string $stateMachineArn,
        ExecutionNameGeneratorInterface $nameGenerator,
        ClockInterface $clock
    ) {
        if ($stateMachineArn === '') {
            throw new \InvalidArgumentException(
                'stateMachineArn cannot be empty.'
                . ' Please set graceful-scheduler.stepfunctions.state_machine_arn in your config.'
            );
        }
        $this->client = $client;
        $this->stateMachineArn = $stateMachineArn;
        $this->nameGenerator = $nameGenerator;
        $this->clock = $clock;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt
    ): DispatchResultInterface {
        $mutexName = $event->mutexName();
        $command = $event->command;
        $executionName = $this->nameGenerator->generate($event, $dueAt);

        try {
            $input = (new Payload($command, $mutexName, $dueAt))->toJson();

            $result = $this->client->startExecution([
                'stateMachineArn' => $this->stateMachineArn,
                'name' => $executionName,
                'input' => $input,
            ]);

            return new StartedStepFunctionsDispatchResult(
                $result->getExecutionArn(),
                $executionName,
                $mutexName,
                (string) $command,
                $this->clock->now()
            );
        } catch (ExecutionAlreadyExistsException $e) {
            return new AlreadyRunningStepFunctionsDispatchResult(
                $executionName,
                $mutexName,
                (string) $command,
                $this->clock->now()
            );
        } catch (StepFunctionsException $e) {
            // Handle Step Functions API errors (not ExecutionAlreadyExists)
            return FailedStepFunctionsDispatchResult::failed(
                $executionName,
                $mutexName,
                $command,
                ExceptionFormatter::format($e),
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
