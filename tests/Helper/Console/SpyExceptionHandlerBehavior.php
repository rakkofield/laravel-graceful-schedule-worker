<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

/**
 * Common logic for SpyExceptionHandler (version-specific classes use this trait).
 */
trait SpyExceptionHandlerBehavior
{
    /** @var \Throwable[] */
    private $reported = [];

    /** @var \Throwable|null */
    private $throwOnReport;

    /**
     * Configure report() to throw an exception.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function willThrowOnReport(\Throwable $exception): void
    {
        $this->throwOnReport = $exception;
    }

    /**
     * @return \Throwable[]
     */
    public function getReported(): array
    {
        return $this->reported;
    }

    /**
     * @return int
     */
    public function getReportedCount(): int
    {
        return count($this->reported);
    }

    /**
     * @param \Throwable $e
     * @return void
     */
    protected function doReport($e): void
    {
        $this->reported[] = $e;

        if ($this->throwOnReport !== null) {
            throw $this->throwOnReport;
        }
    }
}
