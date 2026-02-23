<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;

/**
 * Generates lock keys for Step Functions payload.
 *
 * For withoutOverlapping events, produces a stable key (no timestamp).
 * For normal events, produces a key with timestamp to allow parallel runs.
 */
class LockKeyGenerator
{
    /** @var MutexNameSanitizer */
    private $sanitizer;

    /**
     * @param MutexNameSanitizer $sanitizer
     */
    public function __construct(MutexNameSanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    /**
     * Generate a lock key based on mutex name and overlapping configuration.
     *
     * @param string $mutexName
     * @param DateTimeInterface $dueAt
     * @param bool $withoutOverlapping
     * @return string
     */
    public function generate(string $mutexName, DateTimeInterface $dueAt, bool $withoutOverlapping): string
    {
        if ($withoutOverlapping) {
            return $this->sanitizer->buildStableKey($mutexName);
        }

        return $this->sanitizer->buildIdentifier($mutexName, (string) $dueAt->getTimestamp());
    }
}
