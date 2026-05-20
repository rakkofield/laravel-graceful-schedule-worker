<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StepFunctionsDispatchResultFactory;
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

    /** @var StepFunctionsDispatchResultFactory */
    private $resultFactory;

    /**
     * @param StepFunctionsClientInterface $client
     * @param StartExecutionInputFactoryInterface $inputFactory
     * @param StepFunctionsDispatchResultFactory $resultFactory
     */
    public function __construct(
        StepFunctionsClientInterface $client,
        StartExecutionInputFactoryInterface $inputFactory,
        StepFunctionsDispatchResultFactory $resultFactory
    ) {
        $this->client = $client;
        $this->inputFactory = $inputFactory;
        $this->resultFactory = $resultFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt,
        \DateTimeImmutable $dispatchedAt
    ): DispatchResultInterface {
        $mutexName = $event->mutexName();
        $command = implode(' ', $event->getEffectiveCommand());
        $input = $this->inputFactory->create($event, $dueAt, $dispatchedAt);

        try {
            $result = $this->client->startExecution($input);

            return $this->resultFactory->started(
                $result->getExecutionArn(),
                $input->getName(),
                $mutexName,
                $command,
                $dispatchedAt
            );
        } catch (ExecutionAlreadyExistsException $e) {
            return $this->resultFactory->alreadyRunning(
                $input->getName(),
                $mutexName,
                $command,
                $dispatchedAt
            );
        } catch (StepFunctionsException $e) {
            return $this->resultFactory->failed(
                $input->getName(),
                $mutexName,
                $command,
                $e,
                $dispatchedAt
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
