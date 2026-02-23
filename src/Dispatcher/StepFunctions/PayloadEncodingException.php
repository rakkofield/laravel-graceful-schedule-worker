<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use RuntimeException;

/**
 * Exception for payload JSON encoding failures.
 *
 * This is not a Step Functions operation failure, but a local input preparation failure.
 */
class PayloadEncodingException extends RuntimeException
{
}
