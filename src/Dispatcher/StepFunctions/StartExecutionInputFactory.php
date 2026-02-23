<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

class StartExecutionInputFactory implements StartExecutionInputFactoryInterface
{
    /** @var ExecutionNameGeneratorInterface */
    private $nameGenerator;

    /** @var PayloadBuilderInterface */
    private $payloadBuilder;

    /** @var int */
    private $lockTtlSeconds;

    /**
     * @param ExecutionNameGeneratorInterface $nameGenerator
     * @param PayloadBuilderInterface $payloadBuilder
     * @param int $lockTtlSeconds
     */
    public function __construct(
        ExecutionNameGeneratorInterface $nameGenerator,
        PayloadBuilderInterface $payloadBuilder,
        int $lockTtlSeconds
    ) {
        $this->nameGenerator = $nameGenerator;
        $this->payloadBuilder = $payloadBuilder;
        $this->lockTtlSeconds = $lockTtlSeconds;
    }

    /**
     * {@inheritdoc}
     */
    public function create(ClockAwareEvent $event, DateTimeInterface $dueAt): StartExecutionInput
    {
        $payload = $this->payloadBuilder->build($event, $dueAt, $this->lockTtlSeconds);
        $executionName = $this->nameGenerator->generate($event, $dueAt);

        return new StartExecutionInput($executionName, $payload->toJson());
    }
}
