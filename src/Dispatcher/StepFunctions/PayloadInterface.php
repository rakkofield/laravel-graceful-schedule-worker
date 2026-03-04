<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Contract for Step Functions StartExecution payload.
 *
 * Implement this interface to provide a custom payload structure.
 */
interface PayloadInterface
{
    /**
     * Encode the payload as a JSON string.
     *
     * @return string
     * @throws PayloadEncodingException if encoding fails
     */
    public function toJson(): string;

    /**
     * Get the command this payload represents.
     *
     * @return string[]
     */
    public function getCommand(): array;
}
