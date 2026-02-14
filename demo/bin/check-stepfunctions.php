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
echo str_repeat('-', 70) . "\n";
printf("%-30s %-15s %s\n", 'Name', 'Status', 'Start Date');
echo str_repeat('-', 70) . "\n";

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

foreach ($executions as $exec) {
    $name = $exec['name'];
    $status = $exec['status'];
    $startDate = $exec['startDate']->format('Y-m-d H:i:s');
    printf("%-30s %-15s %s\n", $name, $status, $startDate);
}

echo str_repeat('-', 70) . "\n";
echo "Total: " . count($executions) . " execution(s)\n";
