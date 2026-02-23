<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Default PayloadBuilder implementation.
 *
 * Extracts command, mutexName, dueAt, lockKey, and ttl
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
    public function build(ClockAwareEvent $event, DateTimeInterface $dueAt, int $lockTtlSeconds): Payload
    {
        $command = $event->getRawCommand() ?? $event->command;
        $mutexName = $event->mutexName();
        $lockKey = $this->lockKeyGenerator->generate($mutexName, $dueAt, $event->withoutOverlapping);
        $ttl = $dueAt->getTimestamp() + $lockTtlSeconds;

        return new Payload(
            $command,
            $mutexName,
            $dueAt->format(DateTimeInterface::ATOM),
            $lockKey,
            $ttl
        );
    }
}
