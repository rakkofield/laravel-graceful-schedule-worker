<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

/**
 * Marker interface representing a skipped dispatch result.
 *
 * Used when dispatch is skipped, such as lock acquisition failure.
 *
 * Used for skip checks via instanceof.
 */
interface SkippedDispatchResultInterface extends DispatchResultInterface
{
    /**
     * Get the skip reason.
     *
     * @return string Skip reason (e.g., 'lock_not_acquired')
     */
    public function getReason(): string;
}
