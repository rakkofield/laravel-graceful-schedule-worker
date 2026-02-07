<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker;

use Psr\Log\AbstractLogger;

/**
 * テスト用のロガー実装
 *
 * ログメッセージを記録し、テストで検証できるようにする
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
     * 記録されたログを取得
     *
     * @return array<array{level: string, message: string, context: array}>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * 指定レベルのログを取得
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
     * ログにメッセージが含まれているかをチェック
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
     * リセット
     *
     * @return void
     */
    public function reset(): void
    {
        $this->logs = [];
    }
}
