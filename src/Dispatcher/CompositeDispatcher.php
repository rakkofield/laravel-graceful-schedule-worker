<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
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

    /** @var string Injected via DI (config is only accessed in ServiceProvider) */
    private $defaultType;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param array<string, ScheduleDispatcherInterface> $dispatchers
     * @param string $defaultType
     * @param LoggerInterface $logger Logger
     * @throws \InvalidArgumentException If dispatchers is empty or defaultType does not exist
     */
    public function __construct(array $dispatchers, string $defaultType, LoggerInterface $logger)
    {
        if (empty($dispatchers)) {
            throw new \InvalidArgumentException('Dispatchers array cannot be empty');
        }

        if (!isset($dispatchers[$defaultType])) {
            $availableTypes = implode(', ', array_keys($dispatchers));
            throw new \InvalidArgumentException(
                "Default dispatcher type '{$defaultType}' not found in dispatchers. Available types: {$availableTypes}"
            );
        }

        $this->dispatchers = $dispatchers;
        $this->defaultType = $defaultType;
        $this->logger = $logger;
    }

    /**
     * Dispatch a single event.
     *
     * @param Event $event The schedule event to execute
     * @param Container $container Laravel container instance
     * @param DateTimeInterface $dueAt Scheduled due time
     * @return DispatchResultInterface Dispatch result
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        $type = $this->resolveDispatcherType($event);

        if (!isset($this->dispatchers[$type])) {
            $availableTypes = implode(', ', array_keys($this->dispatchers));
            throw new \InvalidArgumentException(
                "Unknown dispatcher type: {$type}. Available types: {$availableTypes}"
            );
        }

        return $this->dispatchers[$type]->dispatchEvent($event, $container, $dueAt);
    }

    /**
     * Resolve the dispatcher type to use from the event.
     *
     * @param Event $event
     * @return string
     */
    private function resolveDispatcherType(Event $event): string
    {
        if ($event instanceof ClockAwareEvent && $event->getDispatcherType() !== null) {
            return $event->getDispatcherType();
        }
        return $this->defaultType;
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        foreach ($this->dispatchers as $type => $dispatcher) {
            try {
                $dispatcher->cleanup();
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
