<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

class FakeExceptionReporter implements ExceptionReporterInterface
{
    /** @var \Throwable[] */
    private $reported = [];

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

    public function report(\Throwable $e): void
    {
        $this->reported[] = $e;
    }
}
