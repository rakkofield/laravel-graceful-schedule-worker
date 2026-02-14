<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

/**
 * Value object representing a finish command template for schedule:finish.
 *
 * Uses string concatenation instead of sprintf to avoid % character conflicts
 * in output paths.
 */
final class FinishCommandTemplate
{
    /** @var string */
    private $commandPrefix;

    /** @var string */
    private $outputSuffix;

    /**
     * @param string $commandPrefix e.g. '/path/to/artisan' schedule:finish "mutex-name"
     * @param string $outputSuffix e.g. >> '/path/to/output' 2>&1
     */
    public function __construct(string $commandPrefix, string $outputSuffix)
    {
        $this->commandPrefix = $commandPrefix;
        $this->outputSuffix = $outputSuffix;
    }

    /**
     * Build the complete finish command with the given exit code.
     *
     * @param int $exitCode
     * @return string
     */
    public function buildCommand(int $exitCode): string
    {
        return $this->commandPrefix . ' ' . $exitCode . ' ' . $this->outputSuffix;
    }
}
