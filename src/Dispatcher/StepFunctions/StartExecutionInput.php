<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Immutable DTO representing the input for a Step Functions StartExecution call.
 */
class StartExecutionInput
{
    /** @var string */
    private $name;

    /** @var string */
    private $input;

    /**
     * @param string $name Execution name
     * @param string $input JSON-encoded input payload
     */
    public function __construct(string $name, string $input)
    {
        $this->name = $name;
        $this->input = $input;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getInput(): string
    {
        return $this->input;
    }
}
