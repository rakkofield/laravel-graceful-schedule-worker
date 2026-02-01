<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Step Functions Execution Name を生成するクラス
 *
 * Execution Name の制約:
 * - 最大 80 文字
 * - 使用可能文字: a-z, A-Z, 0-9, -, _
 */
class ExecutionNameGenerator
{
    /**
     * Execution Name の最大長
     */
    private const MAX_LENGTH = 80;

    /**
     * mutexName とタイムスタンプから Execution Name を生成
     *
     * @param string $mutexName Event の mutex 名
     * @param string $timestamp タイムスタンプ（ISO 8601 形式など）
     * @return string Execution Name
     */
    public function generate(string $mutexName, string $timestamp): string
    {
        // 不正な文字を置換
        $sanitizedMutex = $this->sanitize($mutexName);
        $sanitizedTimestamp = $this->sanitize($timestamp);

        $name = $sanitizedMutex . '_' . $sanitizedTimestamp;

        // 80 文字を超える場合はハッシュを使用
        if (strlen($name) > self::MAX_LENGTH) {
            $hash = substr(md5($mutexName . $timestamp), 0, 16);
            $maxMutexLength = self::MAX_LENGTH - strlen($hash) - 1;
            $truncatedMutex = substr($sanitizedMutex, 0, $maxMutexLength);
            $name = $truncatedMutex . '_' . $hash;
        }

        return $name;
    }

    /**
     * 不正な文字を置換
     *
     * @param string $value
     * @return string
     */
    private function sanitize(string $value): string
    {
        /** @var string $result */
        $result = preg_replace('/[^a-zA-Z0-9_-]/', '-', $value);
        return $result;
    }
}
