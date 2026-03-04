<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Default PayloadBuilder implementation.
 *
 * Extracts command, mutexName, dueAt, lockKey, and expiresAt
 * from the event and builds a Payload DTO.
 */
class PayloadBuilder implements PayloadBuilderInterface
{
    /** @var LockKeyGenerator */
    private $lockKeyGenerator;

    /**
     * @param LockKeyGenerator $lockKeyGenerator
     */
    public function __construct(LockKeyGenerator $lockKeyGenerator)
    {
        $this->lockKeyGenerator = $lockKeyGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function build(ClockAwareEvent $event, DateTimeInterface $dueAt, int $lockTtlSeconds): PayloadInterface
    {
        $command = $event->getEffectiveCommand();
        $mutexName = $event->mutexName();
        $lockKey = $this->lockKeyGenerator->generate($event, $dueAt);

        $lockTtl = $event->resolveLockTtlSeconds($lockTtlSeconds);
        $expiresAt = $dueAt->getTimestamp() + $lockTtl;

        return new Payload(
            $command,
            $mutexName,
            $dueAt->format(DateTimeInterface::ATOM),
            $lockKey,
            $expiresAt
        );
    }
}
