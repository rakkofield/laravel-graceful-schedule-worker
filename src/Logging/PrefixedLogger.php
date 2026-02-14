<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * PSR LoggerInterface decorator that auto-prepends a prefix to all log messages.
 */
class PrefixedLogger extends AbstractLogger
{
    private const PREFIX = '[GracefulScheduleWorker] ';

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param mixed $level
     * @param string $message
     * @param mixed[] $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, self::PREFIX . $message, $context);
    }
}
