<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatcherType;

/**
 * Failure result class for LocalDispatcher.
 */
class FailedLocalDispatchResult extends AbstractDispatchResult implements FailedDispatchResultInterface
{
    /** @var string */
    private $error;

    /** @var \Throwable */
    private $exception;

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param \Throwable $exception
     * @param DateTimeImmutable $dispatchedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        \Throwable $exception,
        DateTimeImmutable $dispatchedAt
    ) {
        parent::__construct($eventIdentifier, $eventCommand, DispatcherType::LOCAL, $dispatchedAt);
        $this->error = self::formatException($exception);
        $this->exception = $exception;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * {@inheritdoc}
     */
    public function getException(): ?\Throwable
    {
        return $this->exception;
    }
}
