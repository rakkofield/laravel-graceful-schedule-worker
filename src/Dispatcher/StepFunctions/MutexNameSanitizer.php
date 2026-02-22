<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Sanitizes mutex names for use in AWS resource identifiers.
 *
 * Replaces characters not in [a-zA-Z0-9_-] with hyphens.
 */
final class MutexNameSanitizer
{
    private const MAX_LENGTH = 80;

    /**
     * Replace characters invalid for AWS resource identifiers with hyphens.
     *
     * @param string $value
     * @return string
     */
    public static function sanitize(string $value): string
    {
        /** @var string $result */
        $result = preg_replace('/[^a-zA-Z0-9_-]/', '-', $value);
        return $result;
    }

    /**
     * Build a sanitized identifier from mutexName and timestamp, truncating with md5 hash if needed.
     *
     * @param string $mutexName Raw mutex name (before sanitization)
     * @param string $timestamp Timestamp string to append
     * @return string Identifier guaranteed to be at most MAX_LENGTH characters
     */
    public static function buildIdentifier(string $mutexName, string $timestamp): string
    {
        $sanitizedMutex = self::sanitize($mutexName);
        $identifier = $sanitizedMutex . '_' . $timestamp;

        if (strlen($identifier) > self::MAX_LENGTH) {
            $hash = substr(md5($mutexName . $timestamp), 0, 16);
            $maxMutexLength = self::MAX_LENGTH - strlen($hash) - 1;
            $truncatedMutex = substr($sanitizedMutex, 0, $maxMutexLength);
            $identifier = $truncatedMutex . '_' . $hash;
        }

        return $identifier;
    }

    /**
     * Build a sanitized stable key from mutexName only (no timestamp).
     *
     * Used for withoutOverlapping jobs where the lock must persist
     * across different dueAt times.
     *
     * @param string $mutexName Raw mutex name (before sanitization)
     * @return string Key guaranteed to be at most MAX_LENGTH characters
     */
    public static function buildStableKey(string $mutexName): string
    {
        $sanitized = self::sanitize($mutexName);

        if (strlen($sanitized) > self::MAX_LENGTH) {
            $hash = substr(md5($mutexName), 0, 16);
            $maxLength = self::MAX_LENGTH - strlen($hash) - 1;
            $sanitized = substr($sanitized, 0, $maxLength) . '_' . $hash;
        }

        return $sanitized;
    }
}
