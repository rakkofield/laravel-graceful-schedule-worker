<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

/**
 * Marker interface representing a successful dispatch result.
 *
 * Used for success checks via instanceof.
 */
interface StartedDispatchResultInterface extends DispatchResultInterface
{
}
