<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * Generates Step Functions Execution Names.
 *
 * Execution Name constraints:
 * - Maximum 80 characters
 * - Allowed characters: a-z, A-Z, 0-9, -, _
 */
class ExecutionNameGenerator implements ExecutionNameGeneratorInterface
{
    /**
     * Maximum length for Execution Name.
     */
    private const MAX_LENGTH = 80;

    /**
     * {@inheritdoc}
     */
    public function generate(ClockAwareEvent $event, DateTimeInterface $dueAt): string
    {
        $mutexName = $event->mutexName();
        $timestamp = (string) $dueAt->getTimestamp();

        // Replace invalid characters
        $sanitizedMutex = $this->sanitize($mutexName);

        $name = $sanitizedMutex . '_' . $timestamp;

        // Use hash if exceeding 80 characters
        if (strlen($name) > self::MAX_LENGTH) {
            $hash = substr(md5($mutexName . $timestamp), 0, 16);
            $maxMutexLength = self::MAX_LENGTH - strlen($hash) - 1;
            $truncatedMutex = substr($sanitizedMutex, 0, $maxMutexLength);
            $name = $truncatedMutex . '_' . $hash;
        }

        return $name;
    }

    /**
     * Replace invalid characters.
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
