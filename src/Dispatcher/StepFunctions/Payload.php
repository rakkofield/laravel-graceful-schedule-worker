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

    /** @var string */
    private $lockKey;

    /** @var int */
    private $ttl;

    public function __construct(
        string $command,
        string $mutexName,
        DateTimeInterface $dueAt,
        string $lockKey,
        int $ttl
    ) {
        $this->command = $command;
        $this->mutexName = $mutexName;
        $this->dueAt = $dueAt;
        $this->lockKey = $lockKey;
        $this->ttl = $ttl;
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
            'lockKey' => $this->lockKey,
            'ttl' => $this->ttl,
        ]);
        if ($encoded === false) {
            throw new StepFunctionsException('Failed to encode input JSON: ' . json_last_error_msg());
        }
        return $encoded;
    }
}
