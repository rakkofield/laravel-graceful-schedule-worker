<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Immutable DTO representing the Step Functions StartExecution input payload.
 */
class Payload
{
    /** @var string */
    private $command;

    /** @var string */
    private $mutexName;

    /** @var string */
    private $dueAt;

    /** @var string */
    private $lockKey;

    /** @var int */
    private $ttl;

    /**
     * @param string $command
     * @param string $mutexName
     * @param string $dueAt ISO 8601 formatted date string
     * @param string $lockKey
     * @param int $ttl
     */
    public function __construct(
        string $command,
        string $mutexName,
        string $dueAt,
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
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @return string
     */
    public function getMutexName(): string
    {
        return $this->mutexName;
    }

    /**
     * @return string
     */
    public function getDueAt(): string
    {
        return $this->dueAt;
    }

    /**
     * @return string
     */
    public function getLockKey(): string
    {
        return $this->lockKey;
    }

    /**
     * @return int
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Encode the payload as a JSON string.
     *
     * @return string
     * @throws StepFunctionsException if JSON encoding fails
     */
    public function toJson(): string
    {
        $encoded = json_encode([
            'command' => $this->command,
            'mutexName' => $this->mutexName,
            'dueAt' => $this->dueAt,
            'lockKey' => $this->lockKey,
            'ttl' => $this->ttl,
        ]);

        if ($encoded === false) {
            throw new StepFunctionsException('Failed to encode input JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }
}
