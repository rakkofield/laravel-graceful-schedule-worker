<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\DispatchResultInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Holds multiple dispatchers and delegates based on event type.
 */
class CompositeDispatcher implements ScheduleDispatcherInterface
{
    /** @var array<string, ScheduleDispatcherInterface> */
    private $dispatchers;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param array<string, ScheduleDispatcherInterface> $dispatchers
     * @param LoggerInterface $logger Logger
     * @throws \InvalidArgumentException If dispatchers is empty
     */
    public function __construct(array $dispatchers, LoggerInterface $logger)
    {
        if (empty($dispatchers)) {
            throw new \InvalidArgumentException('Dispatchers array cannot be empty');
        }

        $this->dispatchers = $dispatchers;
        $this->logger = $logger;
    }

    /**
     * Dispatch a single event.
     *
     * @param ClockAwareEvent $event The schedule event to execute
     * @param Container $container Laravel container instance
     * @param DateTimeInterface $dueAt Scheduled due time
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(
        ClockAwareEvent $event,
        Container $container,
        DateTimeInterface $dueAt
    ): DispatchResultInterface {
        $type = $event->getDispatcherType();

        if (!isset($this->dispatchers[$type])) {
            $availableTypes = implode(', ', array_keys($this->dispatchers));
            throw new \InvalidArgumentException(
                "Unknown dispatcher type: {$type}. Available types: {$availableTypes}"
            );
        }

        return $this->dispatchers[$type]->dispatchEvent($event, $container, $dueAt);
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        foreach ($this->dispatchers as $type => $dispatcher) {
            try {
                $dispatcher->cleanup();
            } catch (\Throwable $e) {
                // Ensure one dispatcher's failure does not affect others
                $this->logger->warning('[GracefulScheduleWorker] Failed to cleanup dispatcher', [
                    'dispatcher' => $type,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        foreach ($this->dispatchers as $type => $dispatcher) {
            try {
                $dispatcher->stopAll();
            } catch (\Throwable $e) {
                // Ensure one dispatcher's failure does not affect others
                $this->logger->warning('[GracefulScheduleWorker] Failed to stop dispatcher', [
                    'dispatcher' => $type,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
