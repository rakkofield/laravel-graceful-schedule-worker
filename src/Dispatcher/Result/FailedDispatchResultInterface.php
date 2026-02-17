<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

/**
 * Interface representing a failed dispatch result.
 */
interface FailedDispatchResultInterface extends DispatchResultInterface
{
    /**
     * Get the error message.
     *
     * @return string Error message
     */
    public function getError(): string;

    /**
     * Get the thrown exception.
     *
     * @return \Throwable|null Exception
     */
    public function getException(): ?\Throwable;
}
