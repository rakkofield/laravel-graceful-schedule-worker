<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;

/**
 * Generates lock keys for DynamoDB-based distributed locking.
 *
 * Lock key format:
 * - Normal: {sanitized_mutexName}_{timestamp}
 * - withoutOverlapping: {sanitized_mutexName} (no timestamp, shared across dueAt times)
 *
 * Truncated with md5 hash when exceeding 80 characters.
 */
class LockKeyGenerator
{
    /**
     * Generate a lock key from mutexName and dueAt.
     *
     * When $withoutOverlapping is true, the lock key excludes the timestamp
     * so that the same DynamoDB lock record is used across different dueAt times,
     * preventing overlapping executions.
     *
     * @param string $mutexName
     * @param DateTimeInterface $dueAt
     * @param bool $withoutOverlapping
     * @return string
     */
    public function generate(string $mutexName, DateTimeInterface $dueAt, bool $withoutOverlapping): string
    {
        if ($withoutOverlapping) {
            return MutexNameSanitizer::buildStableKey($mutexName);
        }

        return MutexNameSanitizer::buildIdentifier($mutexName, (string) $dueAt->getTimestamp());
    }
}
