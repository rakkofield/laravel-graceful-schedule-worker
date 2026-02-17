<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SpyExceptionHandler for Laravel 6 (Symfony 4) — Exception type hints.
 */
class SpyExceptionHandlerLaravel6 implements ExceptionHandler
{
    use SpyExceptionHandlerBehavior;

    /**
     * {@inheritdoc}
     *
     * @param Exception $e
     */
    public function report(Exception $e)
    {
        $this->doReport($e);
    }

    /**
     * {@inheritdoc}
     *
     * @param Exception $e
     * @return bool
     */
    public function shouldReport(Exception $e)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param Exception $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Exception $e)
    {
        throw $e;
    }

    /**
     * {@inheritdoc}
     *
     * @param OutputInterface $output
     * @param Exception $e
     * @return void
     */
    public function renderForConsole($output, Exception $e)
    {
        throw $e;
    }
}
