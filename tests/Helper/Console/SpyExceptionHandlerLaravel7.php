<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * SpyExceptionHandler for Laravel 7+ (Symfony 5) — Throwable type hints.
 */
class SpyExceptionHandlerLaravel7 implements ExceptionHandler
{
    use SpyExceptionHandlerBehavior;

    /**
     * {@inheritdoc}
     *
     * @param Throwable $e
     */
    public function report(Throwable $e)
    {
        $this->doReport($e);
    }

    /**
     * {@inheritdoc}
     *
     * @param Throwable $e
     * @return bool
     */
    public function shouldReport(Throwable $e)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param Throwable $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $e)
    {
        throw $e;
    }

    /**
     * {@inheritdoc}
     *
     * @param OutputInterface $output
     * @param Throwable $e
     * @return void
     */
    public function renderForConsole($output, Throwable $e)
    {
        throw $e;
    }
}
