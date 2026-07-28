<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Default PayloadBuilder implementation.
 *
 * Extracts command, mutexName, dueAt, lockKey, expiresAt and dispatchedAt from the
 * event, derives timeoutSeconds via StepFunctionsTimeoutSettings, and builds a
 * Payload DTO.
 */
class PayloadBuilder implements PayloadBuilderInterface
{
    /** @var LockKeyGenerator */
    private $lockKeyGenerator;

    /** @var StepFunctionsTimeoutSettings */
    private $timeoutSettings;

    /**
     * @param LockKeyGenerator $lockKeyGenerator
     * @param StepFunctionsTimeoutSettings|null $timeoutSettings Defaults to the documented config defaults
     */
    public function __construct(
        LockKeyGenerator $lockKeyGenerator,
        ?StepFunctionsTimeoutSettings $timeoutSettings = null
    ) {
        $this->lockKeyGenerator = $lockKeyGenerator;
        $this->timeoutSettings = $timeoutSettings !== null
            ? $timeoutSettings
            : new StepFunctionsTimeoutSettings(
                StepFunctionsTimeoutSettings::DEFAULT_LOCK_TTL,
                StepFunctionsTimeoutSettings::DEFAULT_LOCK_RELEASE_BUFFER
            );
    }

    /**
     * {@inheritdoc}
     */
    public function build(
        ClockAwareEvent $event,
        DateTimeInterface $dueAt,
        int $lockTtlSeconds,
        DateTimeInterface $dispatchedAt
    ): PayloadInterface {
        $command = $event->getEffectiveCommand();
        $mutexName = $event->mutexName();
        $lockKey = $this->lockKeyGenerator->generate($event, $dueAt);

        $lockTtl = $event->resolveLockTtlSeconds($lockTtlSeconds);
        $expiresAt = $dueAt->getTimestamp() + $lockTtl;

        $timeoutSeconds = $this->timeoutSettings->deriveTimeoutSeconds(
            $expiresAt,
            $dispatchedAt->getTimestamp(),
            $lockTtl,
            $event->getTaskTimeoutSeconds()
        );

        return new Payload(
            $command,
            $mutexName,
            $dueAt->format(DateTimeInterface::ATOM),
            $lockKey,
            $expiresAt,
            $dispatchedAt->getTimestamp(),
            $timeoutSeconds
        );
    }
}
