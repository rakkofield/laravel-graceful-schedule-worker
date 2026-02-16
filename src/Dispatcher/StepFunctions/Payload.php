<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;

/**
 * Value object for Step Functions execution input payload.
 */
final class Payload
{
    /** @var string */
    private $command;

    /** @var string */
    private $mutexName;

    /** @var DateTimeInterface */
    private $dueAt;

    public function __construct(string $command, string $mutexName, DateTimeInterface $dueAt)
    {
        $this->command = $command;
        $this->mutexName = $mutexName;
        $this->dueAt = $dueAt;
    }

    /**
     * @return string JSON string
     * @throws StepFunctionsException if encoding fails
     */
    public function toJson(): string
    {
        $encoded = json_encode([
            'command' => $this->command,
            'mutexName' => $this->mutexName,
            'dueAt' => $this->dueAt->format(DateTimeInterface::ATOM),
        ]);
        if ($encoded === false) {
            throw new StepFunctionsException('Failed to encode input JSON: ' . json_last_error_msg());
        }
        return $encoded;
    }
}
