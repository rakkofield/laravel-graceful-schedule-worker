<?php

/**
 * Check Step Functions execution history in moto.
 *
 * Displays a table of all executions for the demo state machine.
 */

require __DIR__ . '/../vendor/autoload.php';

$endpoint = getenv('SFN_ENDPOINT') ?: 'http://localhost:5001';
$region = getenv('AWS_DEFAULT_REGION') ?: 'ap-northeast-1';

$client = new \Aws\Sfn\SfnClient([
    'region' => $region,
    'version' => 'latest',
    'endpoint' => $endpoint,
    'credentials' => [
        'key' => getenv('AWS_ACCESS_KEY_ID') ?: 'test',
        'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: 'test',
    ],
]);

// Find the state machine ARN
try {
    $result = $client->listStateMachines();
    $machines = $result->get('stateMachines');
} catch (\Aws\Exception\AwsException $e) {
    echo "Error listing state machines: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($machines)) {
    echo "No state machines found.\n";
    exit(0);
}

$stateMachineArn = $machines[0]['stateMachineArn'];
echo "State Machine: " . $machines[0]['name'] . "\n";
echo str_repeat('-', 98) . "\n";
printf(
    "%-22s %-26s %-10s %-19s %8s %8s\n",
    'Name',
    'Command',
    'Status',
    'Start Date',
    'Timeout',
    'LockLeft'
);
echo str_repeat('-', 98) . "\n";

// List executions
try {
    $result = $client->listExecutions([
        'stateMachineArn' => $stateMachineArn,
    ]);
    $executions = $result->get('executions');
} catch (\Aws\Exception\AwsException $e) {
    echo "Error listing executions: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($executions)) {
    echo "(no executions)\n";
    exit(0);
}

/**
 * Read the command and the timeout-related numbers out of one execution's input.
 *
 * `Timeout` is the value the state machine would apply through
 * `TimeoutSecondsPath: "$.timeoutSeconds"`; `LockLeft` is how much of the DynamoDB lock
 * lifetime was still ahead at dispatch time (expiresAt - dispatchedAt). For a derived
 * timeout the two differ by exactly `lock_release_buffer`; a timeoutAfter() declaration
 * shows up as a smaller, flat Timeout.
 *
 * The `Command` column is what identifies a row: execution names are sha1-based, so the
 * dispatched command is the only readable link back to the schedule definition.
 *
 * @return array{command: string, timeout: string, lockLeft: string}
 */
function payloadColumns(\Aws\Sfn\SfnClient $client, string $executionArn): array
{
    $unknown = ['command' => '-', 'timeout' => '-', 'lockLeft' => '-'];

    try {
        $description = $client->describeExecution(['executionArn' => $executionArn]);
    } catch (\Aws\Exception\AwsException $e) {
        return $unknown;
    }

    $input = json_decode((string) $description['input'], true);
    if (!is_array($input)) {
        return $unknown;
    }

    $command = isset($input['command']) && is_array($input['command'])
        ? implode(' ', $input['command'])
        : '-';

    // Executions dispatched before timeoutSeconds existed still list fine.
    $timeout = isset($input['timeoutSeconds']) ? (string) $input['timeoutSeconds'] : '-';

    $lockLeft = '-';
    if (isset($input['expiresAt'], $input['dispatchedAt'])) {
        $lockLeft = (string) ((int) $input['expiresAt'] - (int) $input['dispatchedAt']);
    }

    return ['command' => $command, 'timeout' => $timeout, 'lockLeft' => $lockLeft];
}

/**
 * Keep the table aligned: execution names and commands both overflow their columns.
 */
function abbreviate(string $value, int $width): string
{
    return strlen($value) <= $width ? $value : substr($value, 0, $width - 2) . '..';
}

foreach ($executions as $exec) {
    $columns = payloadColumns($client, $exec['executionArn']);
    printf(
        "%-22s %-26s %-10s %-19s %8s %8s\n",
        abbreviate($exec['name'], 22),
        abbreviate($columns['command'], 26),
        $exec['status'],
        $exec['startDate']->format('Y-m-d H:i:s'),
        $columns['timeout'],
        $columns['lockLeft']
    );
}

echo str_repeat('-', 98) . "\n";
echo "Total: " . count($executions) . " execution(s)\n";
echo "\n";
echo "Timeout  = payload timeoutSeconds (what TimeoutSecondsPath would apply)\n";
echo "LockLeft = lock lifetime remaining at dispatch (expiresAt - dispatchedAt)\n";
echo "Derived timeouts sit lock_release_buffer below LockLeft; timeoutAfter() values are flat.\n";
echo "Note: the demo state machine does not declare TimeoutSecondsPath, so nothing enforces\n";
echo "these values here - a Pass state cannot carry it, and moto cannot run the ECS integration.\n";
