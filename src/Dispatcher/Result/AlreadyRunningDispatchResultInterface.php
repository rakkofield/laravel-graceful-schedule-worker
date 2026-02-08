<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result;

/**
 * Marker interface representing a dispatch result for an already running task.
 *
 * Used when duplicate execution is detected, such as
 * Step Functions' ExecutionAlreadyExists.
 *
 * Used for existing execution checks via instanceof.
 */
interface AlreadyRunningDispatchResultInterface extends DispatchResultInterface
{
}
