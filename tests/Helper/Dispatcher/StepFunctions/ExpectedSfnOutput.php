<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use RuntimeException;

/**
 * Builds the expected SFN execution `output` JSON for each test state
 * machine. Each method names the transformation a particular state
 * machine fixture applies to the dispatched payload, so the assertion
 * site reads as "the execution output equals the X-of the dispatched
 * payload" instead of inspecting raw `$output[...]` paths.
 *
 * Mirrors the shapes declared by:
 * - `tests/StepFunctions/state-machine.json` (Pass)
 * - `tests/StepFunctions/state-machine-choice.json` (Choice + branch tag)
 * - `tests/StepFunctions/state-machine-lambda.json` (lambda:invoke +
 *   ResultSelector + ResultPath)
 */
final class ExpectedSfnOutput
{
    /**
     * Layer A: a Pass state machine emits the input payload unchanged.
     */
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
        $data['lambda'] = ['workerResult' => $data];
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
