<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Immutable DTO representing the Step Functions StartExecution input payload.
 */
class Payload implements PayloadInterface
{
    /** @var string[] */
    private $command;

    /** @var string */
    private $mutexName;

    /** @var string */
    private $dueAt;

    /** @var string */
    private $lockKey;

    /** @var int */
    private $expiresAt;

    /** @var int */
    private $dispatchedAt;

    /**
     * @param string[] $command
     * @param string $mutexName
     * @param string $dueAt ISO 8601 formatted date string
     * @param string $lockKey
     * @param int $expiresAt
     * @param int $dispatchedAt Unix timestamp at dispatch time (used by AcquireLock as :now)
     */
    public function __construct(
        array $command,
        string $mutexName,
        string $dueAt,
        string $lockKey,
        int $expiresAt,
        int $dispatchedAt
    ) {
        $this->command = $command;
        $this->mutexName = $mutexName;
        $this->dueAt = $dueAt;
        $this->lockKey = $lockKey;
        $this->expiresAt = $expiresAt;
        $this->dispatchedAt = $dispatchedAt;
    }

    /**
     * @return string[]
     */
    public function getCommand(): array
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
    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * @return int
     */
    public function getDispatchedAt(): int
    {
        return $this->dispatchedAt;
    }

    /**
     * Encode the payload as a JSON string.
     *
     * @return string
     * @throws PayloadEncodingException if JSON encoding fails
     */
    public function toJson(): string
    {
        $encoded = json_encode([
            'command' => $this->command,
            'mutexName' => $this->mutexName,
            'dueAt' => $this->dueAt,
            'lockKey' => $this->lockKey,
            'expiresAt' => $this->expiresAt,
            'dispatchedAt' => $this->dispatchedAt,
        ]);

        if ($encoded === false) {
            throw new PayloadEncodingException('Failed to encode input JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }
}
