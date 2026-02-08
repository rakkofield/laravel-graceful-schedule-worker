<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * DTO representing the result of the StartExecution API.
 */
class StartExecutionResult
{
    /** @var string */
    private $executionArn;

    /** @var \DateTimeInterface */
    private $startDate;

    /**
     * @param string $executionArn
     * @param \DateTimeInterface $startDate
     */
    public function __construct(string $executionArn, \DateTimeInterface $startDate)
    {
        $this->executionArn = $executionArn;
        $this->startDate = $startDate;
    }

    /**
     * Get the Execution ARN.
     *
     * @return string
     */
    public function getExecutionArn(): string
    {
        return $this->executionArn;
    }

    /**
     * Get the start date.
     *
     * @return \DateTimeInterface
     */
    public function getStartDate(): \DateTimeInterface
    {
        return $this->startDate;
    }
}
