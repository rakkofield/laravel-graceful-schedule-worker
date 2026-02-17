<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

/**
 * Constants for dispatcher type identifiers.
 */
final class DispatcherType
{
    public const LOCAL = 'local';
    public const STEP_FUNCTIONS = 'stepfunctions';
}
