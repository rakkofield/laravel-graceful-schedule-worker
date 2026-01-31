<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\DispatchResultInterface;

class FakeDispatchResult implements DispatchResultInterface
{
    /** @var bool */
    private $started;

    /** @var string|null */
    private $error;

    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var string */
    private $dispatcherType;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /**
     * @param bool $started
     * @param string|null $error
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $dispatcherType
     * @param DateTimeImmutable|null $dispatchedAt
     */
    public function __construct(
        bool $started,
        ?string $error,
        string $eventIdentifier,
        string $eventCommand,
        string $dispatcherType,
        ?DateTimeImmutable $dispatchedAt = null
    ) {
        $this->started = $started;
        $this->error = $error;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatcherType = $dispatcherType;
        $this->dispatchedAt = $dispatchedAt ?? new DateTimeImmutable();
    }

    /**
     * Create a successful result.
     *
     * @param string $identifier
     * @param string $command
     * @param string $type
     * @return self
     */
    public static function success(string $identifier, string $command, string $type = 'fake'): self
    {
        return new self(true, null, $identifier, $command, $type);
    }

    /**
     * Create a failed result.
     *
     * @param string $identifier
     * @param string $command
     * @param string $error
     * @param string $type
     * @return self
     */
    public static function failed(string $identifier, string $command, string $error, string $type = 'fake'): self
    {
        return new self(false, $error, $identifier, $command, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventIdentifier(): string
    {
        return $this->eventIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventCommand(): string
    {
        return $this->eventCommand;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatcherType(): string
    {
        return $this->dispatcherType;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatchedAt(): DateTimeImmutable
    {
        return $this->dispatchedAt;
    }
}
