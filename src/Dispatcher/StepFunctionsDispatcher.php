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
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StartExecutionInputFactoryInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsException;
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

    /** @var StartExecutionInputFactoryInterface */
    private $inputFactory;

    /** @var ClockInterface */
    private $clock;

    /**
     * @param StepFunctionsClientInterface $client
     * @param StartExecutionInputFactoryInterface $inputFactory
     * @param ClockInterface $clock
     */
    public function __construct(
        StepFunctionsClientInterface $client,
        StartExecutionInputFactoryInterface $inputFactory,
        ClockInterface $clock
    ) {
        $this->client = $client;
        $this->inputFactory = $inputFactory;
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
        $command = $event->getEffectiveCommand();
        $input = $this->inputFactory->create($event, $dueAt);

        try {
            $result = $this->client->startExecution($input);

            return new StartedStepFunctionsDispatchResult(
                $result->getExecutionArn(),
                $input->getName(),
                $mutexName,
                $command,
                $this->clock->now()
            );
        } catch (ExecutionAlreadyExistsException $e) {
            return new AlreadyRunningStepFunctionsDispatchResult(
                $input->getName(),
                $mutexName,
                $command,
                $this->clock->now()
            );
        } catch (StepFunctionsException $e) {
            return new FailedStepFunctionsDispatchResult(
                $input->getName(),
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
