<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

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
     * Generate a lock key based on the event's mutex name and overlapping configuration.
     *
     * @param ClockAwareEvent $event
     * @param DateTimeInterface $dueAt
     * @return string
     */
    public function generate(ClockAwareEvent $event, DateTimeInterface $dueAt): string
    {
        $mutexName = $event->mutexName();

        if ($event->withoutOverlapping) {
            return $this->sanitizer->buildStableKey($mutexName);
        }

        return $this->sanitizer->buildIdentifier($mutexName, (string) $dueAt->getTimestamp());
    }
}
