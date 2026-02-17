<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker;

use Psr\Log\AbstractLogger;

/**
 * Logger implementation for testing
 *
 * Records log messages so they can be verified in tests
 */
class SpyLogger extends AbstractLogger
{
    /**
     * @var array<array{level: string, message: string, context: array}>
     */
    private $logs = [];

    /**
     * {@inheritdoc}
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => (string) $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Get recorded logs
     *
     * @return array<array{level: string, message: string, context: array}>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Get logs by level
     *
     * @param string $level
     * @return array<array{level: string, message: string, context: array}>
     */
    public function getLogsByLevel(string $level): array
    {
        return array_values(array_filter(
            $this->logs,
            function (array $log) use ($level) {
                return $log['level'] === $level;
            }
        ));
    }

    /**
     * Check if logs contain a message
     *
     * @param string $level
     * @param string $messageContains
     * @return bool
     */
    public function hasLogContaining(string $level, string $messageContains): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === $level && strpos($log['message'], $messageContains) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reset
     *
     * @return void
     */
    public function reset(): void
    {
        $this->logs = [];
    }
}
