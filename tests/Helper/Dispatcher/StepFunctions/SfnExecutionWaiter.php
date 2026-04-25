<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Sfn\SfnClient;
use RuntimeException;

/**
 * Polls a Step Functions execution until it leaves the RUNNING state.
 *
 * On timeout, surfaces the final status, error, cause, and the most
 * recent execution history events so a stuck execution (e.g. moto's
 * deepcopy/RLock crash leaving the executor unable to advance) is
 * debuggable.
 */
final class SfnExecutionWaiter
{
    private const POLL_INTERVAL_MICROSECONDS = 100000;

    /** @var SfnClient */
    private $sfn;

    public function __construct(SfnClient $sfn)
    {
        $this->sfn = $sfn;
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException when the execution never leaves RUNNING.
     */
    public function waitForFinish(string $executionArn, int $maxAttempts = 50): array
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $description = $this->sfn->describeExecution([
                'executionArn' => $executionArn,
            ])->toArray();

            if ($description['status'] !== 'RUNNING') {
                return $description;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        throw new RuntimeException($this->buildTimeoutMessage($executionArn, $maxAttempts));
    }

    private function buildTimeoutMessage(string $executionArn, int $maxAttempts): string
    {
        $final = $this->sfn->describeExecution(['executionArn' => $executionArn])->toArray();
        $history = $this->sfn->getExecutionHistory([
            'executionArn' => $executionArn,
            'maxResults' => 5,
            'reverseOrder' => true,
        ])->toArray();

        return sprintf(
            'Execution %s did not finish within %dms. status=%s error=%s cause=%s history=%s',
            $executionArn,
            $maxAttempts * intdiv(self::POLL_INTERVAL_MICROSECONDS, 1000),
            $final['status'] ?? 'unknown',
            $final['error'] ?? '',
            $final['cause'] ?? '',
            (string) json_encode($history['events'] ?? [])
        );
    }
}
