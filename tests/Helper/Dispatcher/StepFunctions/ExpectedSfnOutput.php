<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use RuntimeException;

/**
 * Mirrors the output shape produced by each test state-machine fixture
 * so assertions read as "the execution output equals the X-of the
 * dispatched payload" rather than inspecting raw `$output[...]` paths:
 *
 * - `tests/StepFunctions/state-machine.json` (Pass)
 * - `tests/StepFunctions/state-machine-choice.json` (Choice + branch tag)
 * - `tests/StepFunctions/state-machine-lambda.json` (lambda:invoke +
 *   ResultSelector + ResultPath)
 */
final class ExpectedSfnOutput
{
    public static function passThroughOf(Payload $payload): string
    {
        return $payload->toJson();
    }

    /**
     * Layer B: the Choice state machine appends a `branch` field via
     * `ResultPath: $.branch` on the terminal Pass.
     */
    public static function choiceBranch(Payload $payload, string $branch): string
    {
        $data = self::decode($payload);
        $data['branch'] = $branch;
        return self::encode($data);
    }

    /**
     * Layer C: the lambda:invoke Task uses `ResultSelector: workerResult.$ = $.Payload`
     * and `ResultPath: $.lambda`, so the Lambda's response (the echoed
     * payload) appears under `lambda.workerResult` while the original
     * input fields remain at the root.
     */
    public static function lambdaEchoOf(Payload $payload): string
    {
        $data = self::decode($payload);
        // Snapshot before adding the `lambda` key so the nested copy
        // mirrors the SFN input pre-Task; PHP's copy-on-write makes the
        // self-referential assignment safe but the intent reads better
        // with the explicit alias.
        $workerResult = $data;
        $data['lambda'] = ['workerResult' => $workerResult];
        return self::encode($data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Payload $payload): array
    {
        $decoded = json_decode($payload->toJson(), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Failed to decode Payload JSON: ' . json_last_error_msg());
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        $json = json_encode($data);
        if ($json === false) {
            throw new RuntimeException('Failed to encode expected output JSON: ' . json_last_error_msg());
        }
        return $json;
    }
}
