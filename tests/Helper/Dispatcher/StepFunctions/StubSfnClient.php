<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sfn\SfnClient;

/**
 * SfnClient Stub for testing
 *
 * Extends AWS SDK SfnClient and stubs out API calls.
 */
class StubSfnClient extends SfnClient
{
    /** @var array<string, mixed>|null */
    private $successResult;

    /** @var string|null */
    private $errorCode;

    /** @var string|null */
    private $errorMessage;

    /**
     * @param array<string, mixed>|null $successResult Result to return on success
     * @param string|null $errorCode Error code (throws error when set)
     * @param string|null $errorMessage Error message
     */
    public function __construct(
        ?array $successResult = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ) {
        // Skip AWS SDK initialization by not calling the parent constructor
        $this->successResult = $successResult;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    /**
     * @param array<string, mixed> $args
     * @return Result
     * @throws AwsException
     */
    public function startExecution(array $args = []): Result
    {
        if ($this->errorCode !== null) {
            $command = new Command('StartExecution');
            throw new AwsException(
                $this->errorMessage ?? 'AWS Error',
                $command,
                [
                    'code' => $this->errorCode,
                    'message' => $this->errorMessage ?? 'AWS Error',
                ]
            );
        }

        return new Result($this->successResult ?? []);
    }
}
