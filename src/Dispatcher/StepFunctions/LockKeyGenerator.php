<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;

/**
 * Generates lock keys for DynamoDB-based distributed locking.
 *
 * Lock key format: {sanitized_mutexName}_{timestamp}
 * Truncated with md5 hash when exceeding 80 characters (delegated to MutexNameSanitizer::buildIdentifier).
 */
class LockKeyGenerator
{
    /**
     * Generate a lock key from mutexName and dueAt.
     *
     * @param string $mutexName
     * @param DateTimeInterface $dueAt
     * @return string
     */
    public function generate(string $mutexName, DateTimeInterface $dueAt): string
    {
        return MutexNameSanitizer::buildIdentifier($mutexName, (string) $dueAt->getTimestamp());
    }
}
