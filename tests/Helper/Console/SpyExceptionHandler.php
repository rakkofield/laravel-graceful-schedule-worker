<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Console\Output\OutputInterface;

class SpyExceptionHandler implements ExceptionHandler
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
     * {@inheritdoc}
     */
    public function report(\Throwable $e)
    {
        $this->reported[] = $e;

        if ($this->throwOnReport !== null) {
            throw $this->throwOnReport;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function shouldReport(\Throwable $e)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Throwable $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, \Throwable $e)
    {
        throw $e;
    }

    /**
     * {@inheritdoc}
     *
     * @param OutputInterface $output
     * @param \Throwable $e
     * @return void
     */
    public function renderForConsole($output, \Throwable $e)
    {
        throw $e;
    }
}
