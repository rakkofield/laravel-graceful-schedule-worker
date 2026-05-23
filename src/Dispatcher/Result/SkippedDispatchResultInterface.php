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
    /** @var string Lock acquisition failed (mutex held by another runner or tracker said no). */
    public const REASON_LOCK_NOT_ACQUIRED = 'lock_not_acquired';

    /**
     * Get the skip reason.
     *
     * @return string Skip reason
     */
    public function getReason(): string;
}
