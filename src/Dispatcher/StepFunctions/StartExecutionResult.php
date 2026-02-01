<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * StartExecution API の結果を表す DTO
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
     * Execution ARN を取得
     *
     * @return string
     */
    public function getExecutionArn(): string
    {
        return $this->executionArn;
    }

    /**
     * 開始日時を取得
     *
     * @return \DateTimeInterface
     */
    public function getStartDate(): \DateTimeInterface
    {
        return $this->startDate;
    }
}
